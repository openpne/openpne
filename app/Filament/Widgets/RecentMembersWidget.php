<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Members\MemberResource;
use App\Models\Member;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentMembersWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Recent registrations'))
            ->query($this->getQuery())
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('Name')),

                TextColumn::make('email')
                    ->label(__('Email'))
                    ->default('-'),

                IconColumn::make('is_login_rejected')
                    ->label(__('Login rejected'))
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime('Y-m-d H:i'),
            ])
            // No member detail/edit page (OpenPNE parity); jump to the moderation list instead.
            ->recordUrl(fn (Member $record): string => MemberResource::getUrl('index'))
            ->paginated(false);
    }

    private function getQuery(): Builder
    {
        return Member::query()
            ->orderBy('created_at', 'desc')
            ->limit(10);
    }
}
