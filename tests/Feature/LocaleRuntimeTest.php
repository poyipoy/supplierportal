<?php

namespace Tests\Feature;

use App\Http\Middleware\ApplyUserLocale;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LocaleRuntimeTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('adasi_portal_test', config('database.connections.mysql.database'));
        $this->assertSame('adasi_portal_test', DB::selectOne('SELECT DATABASE() AS db')->db);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('web')->get('/_phase7/locale', fn () => response()->json(['locale' => app()->getLocale()]));
        Route::middleware('web')->get('/_phase7/forbidden', fn () => abort(403));
        Route::middleware('web')->post('/_phase7/validation', function (Request $request) {
            $request->validate(['email' => 'required|email']);

            return response()->noContent();
        });
    }

    public function test_authenticated_locale_is_available_to_validation_errors_and_all_shells(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->preference()->create([...config('user_preferences.defaults'), 'locale' => 'id']);
        $this->actingAs($user)->getJson('/_phase7/locale')->assertJsonPath('locale', 'id');
        $this->get(route('profile.customization'))->assertOk()->assertSee('<html lang="id"', false)->assertSee('Bahasa Indonesia');
        $this->get('/_phase7/forbidden')->assertForbidden()->assertSee('<html lang="id"', false);
        $this->postJson('/_phase7/validation', [])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame('id', app()->getLocale());
    }

    public function test_every_request_resolves_its_own_user_or_guest_without_browser_detection(): void
    {
        $indonesian = User::factory()->create(['role' => 'admin']);
        $indonesian->preference()->create([...config('user_preferences.defaults'), 'locale' => 'id']);
        $english = User::factory()->create(['role' => 'admin']);
        $this->actingAs($indonesian)->getJson('/_phase7/locale')->assertJsonPath('locale', 'id');
        $this->actingAs($english)->getJson('/_phase7/locale')->assertJsonPath('locale', 'en');
        Auth::forgetGuards();
        $this->withHeader('Accept-Language', 'id-ID,id;q=0.9')->getJson('/_phase7/locale')->assertJsonPath('locale', 'en');
        $this->get(route('login'))->assertOk()->assertSee('<html lang="en"', false);
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $english->id]);
    }

    public function test_locale_middleware_runs_after_session_before_binding_and_controller(): void
    {
        $this->app->make(Kernel::class);
        $route = Route::getRoutes()->getByName('profile.customization');
        $middleware = app('router')->gatherRouteMiddleware($route);
        $locale = array_search(ApplyUserLocale::class, $middleware, true);
        $session = array_search(StartSession::class, $middleware, true);
        $bindings = array_search(SubstituteBindings::class, $middleware, true);
        $this->assertNotFalse($locale);
        $this->assertNotFalse($session);
        $this->assertNotFalse($bindings);
        $this->assertGreaterThan($session, $locale);
        $this->assertLessThan($bindings, $locale);
    }
}
