<?php

declare(strict_types=1);

namespace Fledge\Apm\Tests\Fixtures\Integration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Fledge\Async\delay;

/**
 * The work of one dashboard card in the parallel-cards trace test.
 *
 * Every card has its own table and cache key, so each DB and cache span can
 * be traced back to the card that ran it. The first card waits longest, so
 * all cards are suspended at the same time before any of them finishes.
 */
final class ParallelCards
{
    public const int COUNT = 5;

    /** @var list<string> start/end markers in the order the cards ran */
    public static array $log = [];

    /**
     * @return list<int>
     */
    public static function cards(): array
    {
        return range(1, self::COUNT);
    }

    public static function table(int $card): string
    {
        return "apm_card_{$card}";
    }

    public static function cacheKey(int $card): string
    {
        return "apm-card:{$card}";
    }

    public static function createTables(): void
    {
        foreach (self::cards() as $card) {
            Schema::create(self::table($card), function (Blueprint $table): void {
                $table->id();
                $table->string('label');
            });
        }
    }

    /**
     * @return array{rows: int, cached: mixed}
     */
    public static function work(int $card): array
    {
        self::$log[] = "start {$card}";

        delay(0.004 * (self::COUNT + 1 - $card));

        DB::table(self::table($card))->insert(['label' => "card {$card}"]);
        Cache::put(self::cacheKey($card), $card, 60);

        delay(0.002);

        $rows = DB::table(self::table($card))->where('label', "card {$card}")->count();
        $cached = Cache::get(self::cacheKey($card));

        self::$log[] = "end {$card}";

        return ['rows' => $rows, 'cached' => $cached];
    }
}
