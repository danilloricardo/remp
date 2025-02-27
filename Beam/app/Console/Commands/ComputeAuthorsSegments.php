<?php

namespace App\Console\Commands;

use App\ArticleAggregatedView;
use App\Author;
use App\Mail\AuthorSegmentsResult;
use App\Model\Config;
use App\Segment;
use App\SegmentBrowser;
use App\SegmentGroup;
use App\SegmentUser;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PDO;

class ComputeAuthorsSegments extends Command
{
    const COMMAND = 'segments:compute-author-segments';

    const CONFIG_MIN_RATIO = 'author_segments_min_ratio';
    const CONFIG_MIN_AVERAGE_TIMESPENT = 'author_segments_min_average_timespent';
    const CONFIG_MIN_VIEWS = 'author_segments_min_views';
    const CONFIG_DAYS_IN_PAST = 'author_segments_days_in_past';

    const ALL_CONFIGS = [self::CONFIG_MIN_RATIO, self::CONFIG_MIN_AVERAGE_TIMESPENT, self::CONFIG_MIN_VIEWS, self::CONFIG_DAYS_IN_PAST];

    private $minViews;
    private $minAverageTimespent;
    private $minRatio;
    private $dateThreshold;

    protected $signature = self::COMMAND . ' 
    {--email} 
    {--history}
    {--min_views=} 
    {--min_average_timespent=} 
    {--min_ratio=}';

    protected $description = "Generate authors' segments from aggregated pageviews and timespent data.";

    public function handle()
    {
        ini_set('memory_limit', '512M');
        // Using Cursor on large number of results causing memory issues
        // https://github.com/laravel/framework/issues/14919
        DB::connection()->getPdo()->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        $this->line('');
        $this->line('<info>***** Computing author segments *****</info>');
        $this->line('');

        $email = $this->option('email');

        $this->minViews = Config::loadByName(self::CONFIG_MIN_VIEWS);
        $this->minAverageTimespent = Config::loadByName(self::CONFIG_MIN_AVERAGE_TIMESPENT);
        $this->minRatio = Config::loadByName(self::CONFIG_MIN_RATIO);
        $this->dateThreshold = Carbon::today()->subDays(Config::loadByName(self::CONFIG_DAYS_IN_PAST));

        if ($email) {
            // Only compute segment statistics
            $this->line('Generating authors segments statistics');
            $this->computeAuthorSegments($email);
        } else {
            // Generate real segments
            $this->line('Generating authors segments');
            $this->recomputeBrowsersForAuthorSegments();
            $this->recomputeUsersForAuthorSegments();
            $deletedSegments = self::deleteEmptySegments();
            $this->line('Deleting empty author segments');
            if ($deletedSegments > 0) {
                $this->line("Deleted $deletedSegments segments");
            }
        }

        $this->line(' <info>OK!</info>');
    }

    /**
     * @param $email
     */
    private function computeAuthorSegments($email)
    {
        $minimalViews = $this->option('min_views') ?? $this->minViews;
        $minimalAverageTimespent = $this->option('min_average_timespent') ?? $this->minAverageTimespent;
        $minimalRatio = $this->option('min_ratio') ?? $this->minRatio;
        $historyDays = $this->option('history') ?? 30;

        $results = [];
        $fromDay = Carbon::now()->subDays($historyDays)->toDateString();
        // only 30, 60 and 90 are allowed values
        $columnDays = 'total_views_last_' . $historyDays .'_days';

        $this->line("running browsers query");

        $browsersSql = <<<SQL
    SELECT T.author_id, authors.name, count(*) AS browser_count 
    FROM
      (SELECT browser_id, author_id, sum(pageviews) AS author_browser_views, avg(timespent) AS average_timespent
      FROM article_aggregated_views C JOIN article_author A ON A.article_id = C.article_id
      WHERE timespent <= 3600
      AND date >= ?
      GROUP BY browser_id, author_id
      HAVING
      author_browser_views >= ? AND
      average_timespent >= ? AND
      author_browser_views/(SELECT $columnDays FROM views_per_browser_mv WHERE browser_id = C.browser_id) >= ?
      ) T 
    JOIN authors ON authors.id = T.author_id
    GROUP BY author_id 
    ORDER BY browser_count DESC
SQL;
        $resultsBrowsers = DB::select($browsersSql, [$fromDay, $minimalViews, $minimalAverageTimespent, $minimalRatio]);

        foreach ($resultsBrowsers as $item) {
            $obj = new \stdClass();
            $obj->name = $item->name;
            $obj->browser_count = $item->browser_count;
            $obj->user_count = 0;

            $results[$item->author_id] = $obj;
        }

        $this->line("running users query");

        $usersSql = <<<SQL
    SELECT T.author_id, authors.name, count(*) AS user_count 
    FROM
        (SELECT user_id, author_id, sum(pageviews) AS author_user_views, avg(timespent) AS average_timespent
        FROM article_aggregated_views C JOIN article_author A ON A.article_id = C.article_id
        WHERE timespent <= 3600
        AND user_id <> ''
        AND date >= ?
        GROUP BY user_id, author_id
        HAVING
        author_user_views >= ? AND
        average_timespent >= ? AND
        author_user_views/(SELECT $columnDays FROM views_per_user_mv WHERE user_id = C.user_id) >= ?
        ) T JOIN authors ON authors.id = T.author_id
    GROUP BY author_id ORDER BY user_count DESC
SQL;
        
        $resultsUsers = DB::select($usersSql, [$fromDay, $minimalViews, $minimalAverageTimespent, $minimalRatio]);

        foreach ($resultsUsers as $item) {
            if (!array_key_exists($item->author_id, $results)) {
                $obj = new \stdClass();
                $obj->name = $item->name;
                $obj->browser_count = 0;
                $obj->user_count = 0;
                $results[$item->author_id] = $obj;
            }

            $results[$item->author_id]->user_count = $item->user_count;
        }

        Mail::to($email)->send(
            new AuthorSegmentsResult($results, $minimalViews, $minimalAverageTimespent, $minimalRatio, $historyDays)
        );
    }

    private function recomputeUsersForAuthorSegments()
    {
        $authorUsers = $this->groupDataFor('user_id');

        $this->line("Updating segments users");
        SegmentUser::truncate();

        foreach ($authorUsers as $authorId => $users) {
            if (count($users) === 0) {
                continue;
            }
            $segment = $this->getOrCreateAuthorSegment($authorId);

            foreach (array_chunk($users, 100) as $usersChunk) {
                $toInsert = collect($usersChunk)->map(function ($userId) use ($segment) {
                    return [
                        'segment_id' => $segment->id,
                        'user_id' => $userId,
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ];
                });
                SegmentUser::insert($toInsert->toArray());
            }
        }
    }

    private function recomputeBrowsersForAuthorSegments()
    {
        $authorBrowsers = $this->groupDataFor('browser_id');

        SegmentBrowser::truncate();

        $this->line("Updating segments browsers");

        foreach ($authorBrowsers as $authorId => $browsers) {
            if (count($browsers) === 0) {
                continue;
            }

            $segment = $this->getOrCreateAuthorSegment($authorId);

            foreach (array_chunk($browsers, 100) as $browsersChunk) {
                $toInsert = collect($browsersChunk)->map(function ($browserId) use ($segment) {
                    return [
                        'segment_id' => $segment->id,
                        'browser_id' => $browserId,
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ];
                });
                SegmentBrowser::insert($toInsert->toArray());
            }
        }
    }

    private function aggregatedPageviewsFor($groupParameter)
    {
        $results = [];

        // Not using model to save memory
        $queryItems = DB::table(ArticleAggregatedView::getTableName())
            ->select($groupParameter, DB::raw('sum(pageviews) as total_pageviews'))
            ->whereNotNull($groupParameter)
            ->where('date', '>=', $this->dateThreshold)
            ->groupBy($groupParameter)
            ->cursor();

        foreach ($queryItems as $item) {
            $results[$item->$groupParameter] = (int) $item->total_pageviews;
        }
        return $results;
    }

    private function groupDataFor($groupParameter)
    {
        $this->line("Computing total pageviews for parameter '$groupParameter'");
        $totalPageviews = $this->aggregatedPageviewsFor($groupParameter);
        $this->line("Done");

        $segments = [];
        $this->line("Computing segment items for parameter '$groupParameter'");

        foreach (array_chunk($totalPageviews, 500, true) as $totalPageviewsChunk) {
            $forItems = array_keys($totalPageviewsChunk);

            $queryItems =  DB::table(ArticleAggregatedView::getTableName())->select(
                $groupParameter,
                'author_id',
                DB::raw('sum(pageviews) as total_pageviews'),
                DB::raw('avg(timespent) as average_timespent')
            )
                ->join('article_author', 'article_author.article_id', '=', 'article_aggregated_views.article_id')
                ->whereNotNull($groupParameter)
                ->where('date', '>=', $this->dateThreshold)
                ->whereIn($groupParameter, $forItems)
                ->groupBy([$groupParameter, 'author_id'])
                ->havingRaw('avg(timespent) >= ?', [$this->minAverageTimespent])
                ->cursor();

            foreach ($queryItems as $item) {
                if ($totalPageviews[$item->$groupParameter] === 0) {
                    continue;
                }
                $ratio = (int) $item->total_pageviews / $totalPageviews[$item->$groupParameter];
                if ($ratio >= $this->minRatio && $item->total_pageviews >= $this->minViews) {
                    if (!array_key_exists($item->author_id, $segments)) {
                        $segments[$item->author_id] = [];
                    }
                    $segments[$item->author_id][] = $item->$groupParameter;
                }
            }
        }

        $this->line("Done");

        return $segments;
    }

    private function getOrCreateAuthorSegment($authorId)
    {
        $segmentGroup = SegmentGroup::where(['code' => SegmentGroup::CODE_AUTHORS_SEGMENTS])->first();
        $author = Author::find($authorId);

        return Segment::updateOrCreate([
            'code' => 'author-' . $author->id
        ], [
            'name' => 'Author ' . $author->name,
            'active' => true,
            'segment_group_id' => $segmentGroup->id,
        ]);
    }

    public static function deleteEmptySegments(): int
    {
        $first = DB::table(SegmentUser::getTableName())
            ->select('segment_id')
            ->groupBy('segment_id');

        $unionQuery = DB::table(SegmentBrowser::getTableName())
            ->select('segment_id')
            ->groupBy('segment_id')
            ->union($first);

        return Segment::leftJoinSub($unionQuery, 't', function ($join) {
            $join->on('segments.id', '=', 't.segment_id');
        })->whereNull('t.segment_id')
            ->where('segment_group_id', SegmentGroup::getByCode(SegmentGroup::CODE_AUTHORS_SEGMENTS)->id)
            ->delete();
    }
}
