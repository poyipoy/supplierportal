<?php

namespace Tests\Feature;

use App\View\Components\Ui\Icon;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\AnonymousComponent;
use Illuminate\View\ComponentAttributeBag;
use Tests\TestCase;

/**
 * <x-ui.icon> moved from an anonymous Blade component to a class that renders HTML directly. The fixture was
 * recorded from the anonymous component (every alias with and without `bi-`, sizes, fallbacks, aria/class/escaping,
 * bound attributes and the WhatsApp mark); the output must stay byte-identical.
 */
class UiIconComponentTest extends TestCase
{
    public function test_every_recorded_case_renders_byte_identical_html(): void
    {
        $cases = json_decode(file_get_contents(base_path('tests/Fixtures/ui-icon-snapshots.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertGreaterThan(200, count($cases));

        foreach ($cases as $case) {
            $data = array_map(fn ($value) => is_array($value) && isset($value['__bag']) ? new ComponentAttributeBag($value['__bag']) : $value, $case['data']);

            $this->assertSame($case['html'], Blade::render($case['template'], $data), $case['template']);
        }
    }

    public function test_every_alias_is_covered_and_points_at_an_existing_icon(): void
    {
        $templates = array_column(json_decode(file_get_contents(base_path('tests/Fixtures/ui-icon-snapshots.json')), true), 'template');

        foreach (Icon::ALIASES as $alias => $target) {
            $this->assertContains('<x-ui.icon name="bi-'.$alias.'" />', $templates, "Snapshot misses alias {$alias}");
            $this->assertFileExists(base_path('vendor/technikermathe/blade-lucide-icons/resources/svg/'.$target.'.svg'));
        }
    }

    public function test_size_is_never_inherited_from_an_enclosing_component(): void
    {
        // Inside another component, Blade passes the parent's attributes to nested class components' constructors.
        $parent = new ComponentAttributeBag(['size' => 'lg', 'name' => 'bell', 'class' => 'parent']);

        $html = Blade::render('<x-ui.icon name="house" />', ['attributes' => $parent]);

        $this->assertStringStartsWith('<svg width="18" height="18" stroke-width="1.75" focusable="false" aria-hidden="true" class="ui-icon" ', $html);
        $this->assertSame(Blade::render('<x-ui.icon name="house" />'), $html);
    }

    public function test_views_compiled_against_the_former_anonymous_component_render_the_same_html(): void
    {
        foreach ([['name' => 'bi-check2 ms-1'], ['name' => 'whatsapp', 'size' => 'sm', 'aria-label' => 'Chat'], ['name' => 'nope', 'class' => 'x']] as $attributes) {
            // The code a stale compiled view still runs until view:clear.
            $component = AnonymousComponent::resolve(['view' => 'components.ui.icon', 'data' => []]);
            $component->withName('ui.icon');
            $component->withAttributes($attributes);
            $stale = view($component->resolveView(), $component->data())->render();

            $tag = '<x-ui.icon '.collect($attributes)->map(fn ($value, $key) => $key.'="'.e($value).'"')->implode(' ').' />';
            $this->assertSame(rtrim(Blade::render($tag)), rtrim($stale), $tag);
        }
    }
}
