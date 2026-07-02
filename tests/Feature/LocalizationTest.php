<?php

namespace Tests\Feature;

use App\Enums\GameMode;
use App\Enums\QuestionCategory;
use App\Enums\SessionStatus;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    public function test_enum_labels_translate_per_locale(): void
    {
        app()->setLocale('en');
        $this->assertSame('Hide & Seek', GameMode::HideAndSeek->getLabel());
        $this->assertSame('Open', SessionStatus::Open->getLabel());
        $this->assertSame('Tentacles', QuestionCategory::Tentacles->getLabel());

        app()->setLocale('hu');
        // "Hide & Seek" is the product's brand name — an untranslated proper noun in both locales.
        $this->assertSame('Hide & Seek', GameMode::HideAndSeek->getLabel());
        $this->assertSame('Nyitott', SessionStatus::Open->getLabel());
        $this->assertSame('Csápok', QuestionCategory::Tentacles->getLabel());
    }

    public function test_navigation_groups_and_framework_messages_have_hungarian(): void
    {
        $this->assertSame('Játék', __('navigation.groups.game', [], 'hu'));
        $this->assertSame('Game', __('navigation.groups.game', [], 'en'));

        // Resource labels are Hungarian (e.g. Curses -> Átkok).
        $this->assertSame('Átkok', __('resources.curses.plural', [], 'hu'));
        $this->assertSame('Kérdések', __('resources.questions.plural', [], 'hu'));
        $this->assertSame('Curses', __('resources.curses.plural', [], 'en'));

        // laravel-lang Hungarian framework strings are installed.
        $this->assertNotSame('validation.required', __('validation.required', [], 'hu'));
        $this->assertNotSame(
            __('validation.required', [], 'en'),
            __('validation.required', [], 'hu'),
        );
    }
}
