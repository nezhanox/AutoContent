<?php

namespace App\Filament\Pages;

use App\Models\LlmUsageLog;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

class LlmUsageReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?string $title = 'LLM Usage Report';

    protected string $view = 'filament.pages.llm-usage-report';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                LlmUsageLog::query()
                    // Same fix as TopTopicsWidget (Task 8): LlmUsageLog's `id` is
                    // implicitly cast to int, so a string-concatenation alias would
                    // collapse every group's id to 0. ROW_NUMBER() avoids that.
                    ->selectRaw(
                        "ROW_NUMBER() OVER (ORDER BY SUM(cost) DESC) as id,
                        provider,
                        model,
                        purpose,
                        COUNT(*) as calls,
                        SUM(prompt_tokens) as prompt_tokens,
                        SUM(completion_tokens) as completion_tokens,
                        SUM(prompt_tokens + completion_tokens) as total_tokens,
                        SUM(cost) as total_cost,
                        SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count"
                    )
                    ->groupBy('provider', 'model', 'purpose')
            )
            ->columns([
                TextColumn::make('provider'),
                TextColumn::make('model'),
                TextColumn::make('purpose'),
                TextColumn::make('calls')->numeric(),
                TextColumn::make('prompt_tokens')->numeric(),
                TextColumn::make('completion_tokens')->numeric(),
                TextColumn::make('total_tokens')->numeric(),
                TextColumn::make('total_cost')->money(),
                TextColumn::make('success_rate')
                    ->getStateUsing(fn (LlmUsageLog $record): string => $record->calls > 0
                        ? round($record->success_count / $record->calls * 100, 1).'%'
                        : '—'),
            ])
            ->defaultSort('total_cost', 'desc')
            // GROUP BY means Filament's default key-sort tiebreak would append an
            // invalid `ORDER BY llm_usage_logs.id`; this query has no such column.
            ->defaultKeySort(false);
    }
}
