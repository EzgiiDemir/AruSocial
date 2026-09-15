<?php

namespace App\Filament\Resources\AdminPages\Schemas;

use App\Models\User;
use App\Services\GranularPermissions;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class AdminPageForm
{
    private static function localizedFields(string $locale): array
    {
        return [
            TextInput::make("translations.{$locale}.title")
                ->label(__('panel.pages.title'))
                ->required($locale === 'tr')
                ->maxLength(180),
            Textarea::make("translations.{$locale}.summary")
                ->label(__('panel.pages.summary'))
                ->rows(2),
        ];
    }

    private static function block(string $type, string $label, array $extra = []): Block
    {
        return Block::make($type)
            ->label($label)
            ->schema([
                Hidden::make('id')->default(fn () => (string) Str::uuid()),
                ...$extra,
                Tabs::make('block_languages')->tabs([
                    Tab::make('TR')->schema([
                        TextInput::make('translations.tr.title')->label(__('panel.pages.title')),
                        Textarea::make('translations.tr.body')->label(__('panel.pages.body'))->rows(4),
                    ]),
                    Tab::make('EN')->schema([
                        TextInput::make('translations.en.title')->label(__('panel.pages.title')),
                        Textarea::make('translations.en.body')->label(__('panel.pages.body'))->rows(4),
                    ]),
                    Tab::make('RU')->schema([
                        TextInput::make('translations.ru.title')->label(__('panel.pages.title')),
                        Textarea::make('translations.ru.body')->label(__('panel.pages.body'))->rows(4),
                    ]),
                ])->columnSpanFull(),
                KeyValue::make('settings')->label(__('panel.pages.settings'))->columnSpanFull(),
                Toggle::make('visible_web')->label(__('panel.pages.visible_web'))->default(true),
                Toggle::make('visible_mobile')->label(__('panel.pages.visible_mobile'))->default(true),
                DateTimePicker::make('starts_at')->label(__('panel.pages.starts_at')),
                DateTimePicker::make('ends_at')->label(__('panel.pages.ends_at')),
            ])->columns(2);
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('panel.pages.page_settings'))->schema([
                TextInput::make('title')->label(__('panel.pages.internal_title'))->required()->maxLength(180),
                TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(180),
                Select::make('status')->options(function (): array {
                    $options = [
                        'draft' => __('panel.pages.draft'), 'review' => __('panel.pages.review'),
                        'archived' => __('panel.pages.archived'),
                    ];
                    $user = auth()->user();
                    if ($user instanceof User && (GranularPermissions::allows($user, 'pages.manage') || GranularPermissions::allows($user, 'pages.page.publish'))) {
                        $options['published'] = __('panel.pages.published');
                    }

                    return $options;
                })->required()->default('draft'),
                Select::make('audiences')->multiple()->options([
                    'students' => __('panel.pages.students'),
                    'teachers' => __('panel.pages.teachers'),
                    'staff' => __('panel.pages.staff'),
                    'international' => __('panel.pages.international'),
                ]),
                DateTimePicker::make('publish_at')->label(__('panel.pages.publish_at')),
                DateTimePicker::make('expires_at')->label(__('panel.pages.expires_at')),
                Tabs::make('languages')->tabs([
                    Tab::make('TR')->schema(self::localizedFields('tr')),
                    Tab::make('EN')->schema(self::localizedFields('en')),
                    Tab::make('RU')->schema(self::localizedFields('ru')),
                ])->columnSpanFull(),
            ])->columns(2),
            Section::make(__('panel.pages.blocks'))->description(__('panel.pages.blocks_help'))->schema([
                Builder::make('blocks')
                    ->label(__('panel.pages.blocks'))
                    ->blocks([
                        self::block('hero', __('panel.pages.block_types.hero'), [TextInput::make('media_id')->label(__('panel.pages.media'))]),
                        self::block('heading', __('panel.pages.block_types.heading')),
                        self::block('rich_text', __('panel.pages.block_types.rich_text')),
                        self::block('image', __('panel.pages.block_types.image'), [TextInput::make('media_id')->label(__('panel.pages.media'))]),
                        self::block('video', __('panel.pages.block_types.video'), [TextInput::make('url')->url()]),
                        self::block('gallery', __('panel.pages.block_types.gallery')),
                        self::block('card_list', __('panel.pages.block_types.card_list')),
                        self::block('cta', __('panel.pages.block_types.cta'), [TextInput::make('url')->label('URL')]),
                        self::block('event_list', __('panel.pages.block_types.event_list')),
                        self::block('club_list', __('panel.pages.block_types.club_list')),
                        self::block('faq', __('panel.pages.block_types.faq')),
                        self::block('map', __('panel.pages.block_types.map')),
                        self::block('tour_360', __('panel.pages.block_types.tour_360')),
                        self::block('form', __('panel.pages.block_types.form')),
                        self::block('survey', __('panel.pages.block_types.survey')),
                        self::block('download', __('panel.pages.block_types.download')),
                        self::block('api_data', __('panel.pages.block_types.api_data'), [Select::make('data_source')->options([
                            'events' => 'Events', 'clubs' => 'Clubs', 'sports' => 'Sports', 'services' => 'Services',
                        ])]),
                        self::block('divider', __('panel.pages.block_types.divider')),
                        self::block('columns', __('panel.pages.block_types.columns')),
                    ])
                    ->addActionLabel(__('panel.pages.add_block'))
                    ->reorderable()
                    ->collapsible()
                    ->cloneable()
                    ->columnSpanFull(),
            ]),
        ]);
    }
}
