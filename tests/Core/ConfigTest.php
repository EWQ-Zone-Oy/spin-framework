<?php declare(strict_types=1);

namespace Spin\tests\Core;

use PHPUnit\Framework\TestCase;
use Spin\Core\Config;

/**
 * Tests for the env-override behavior added to Config::get() and config().
 *
 * Only covers what changed in 0.0.37 — the env-var resolution path. JSON
 * loading, set(), save() etc. are not retested here.
 */
class ConfigTest extends TestCase
{
  /** @var string App path containing Config/config-unittest.json */
  private string $appPath;

  /** @var string Environment name used by the Config under test */
  private string $environment = 'unittest';

  /** @var string[] Env vars set during a test, cleared in tearDown */
  private array $envVarsSet = [];

  protected function setUp(): void
  {
    $this->appPath = \realpath(__DIR__ . '/../app');
  }

  protected function tearDown(): void
  {
    foreach ($this->envVarsSet as $name) {
      \putenv($name);
    }
    $this->envVarsSet = [];
  }

  private function setEnv(string $name, string $value): void
  {
    \putenv($name . '=' . $value);
    $this->envVarsSet[] = $name;
  }

  private function makeConfig(): Config
  {
    return new Config($this->appPath, $this->environment);
  }

  /**
   * Baseline: with no env vars, get() returns the JSON value.
   */
  public function testReturnsJsonValueWhenNoEnvVarsSet(): void
  {
    $config = $this->makeConfig();

    $this->assertSame('from-json', $config->get('application.code'));
  }

  /**
   * Env-specific prefix (UNITTEST_*) takes priority over JSON.
   */
  public function testEnvSpecificPrefixOverridesJson(): void
  {
    $this->setEnv('UNITTEST_APPLICATION_CODE', 'from-unittest-env');
    $config = $this->makeConfig();

    $this->assertSame('from-unittest-env', $config->get('application.code'));
  }

  /**
   * Generic env var (without env prefix) overrides JSON.
   */
  public function testGenericEnvVarOverridesJson(): void
  {
    $this->setEnv('APPLICATION_CODE', 'from-generic-env');
    $config = $this->makeConfig();

    $this->assertSame('from-generic-env', $config->get('application.code'));
  }

  /**
   * The priority swap: env-specific wins over generic when both are set.
   * This is the headline behavior change.
   */
  public function testEnvSpecificWinsOverGenericWhenBothSet(): void
  {
    $this->setEnv('APPLICATION_CODE', 'from-generic-env');
    $this->setEnv('UNITTEST_APPLICATION_CODE', 'from-unittest-env');
    $config = $this->makeConfig();

    $this->assertSame('from-unittest-env', $config->get('application.code'));
  }

  /**
   * A prefix from a different environment must not leak in.
   */
  public function testDifferentEnvironmentPrefixIsIgnored(): void
  {
    $this->setEnv('PROD_APPLICATION_CODE', 'from-prod-env');
    $config = $this->makeConfig();

    $this->assertSame('from-json', $config->get('application.code'));
  }

  /**
   * When $env=false, env vars are ignored and JSON wins regardless.
   */
  public function testEnvFalseSkipsEnvLookup(): void
  {
    $this->setEnv('APPLICATION_CODE', 'from-generic-env');
    $this->setEnv('UNITTEST_APPLICATION_CODE', 'from-unittest-env');
    $config = $this->makeConfig();

    $this->assertSame('from-json', $config->get('application.code', null, false));
  }

  /**
   * Dot keys canonicalize to underscored uppercase env var names,
   * including multi-segment keys.
   */
  public function testDotNotationCanonicalizesToUnderscoredUppercase(): void
  {
    $this->setEnv('DATABASE_HOST', 'env-host');
    $this->setEnv('UNITTEST_NESTED_DEEP_VALUE', 'env-deep');
    $config = $this->makeConfig();

    $this->assertSame('env-host', $config->get('database.host'));
    $this->assertSame('env-deep', $config->get('nested.deep.value'));
  }

  /**
   * env() coerces "true"/"false"/"null"/"empty" — confirm these flow
   * through Config::get() rather than being returned as raw strings.
   */
  public function testEnvValueCoercionFlowsThrough(): void
  {
    $this->setEnv('APPLICATION_DEBUG', 'true');
    $config = $this->makeConfig();

    $this->assertTrue($config->get('application.debug'));
  }

  /**
   * Default is returned when neither env nor JSON has the key.
   */
  public function testDefaultReturnedWhenKeyMissingEverywhere(): void
  {
    $config = $this->makeConfig();

    $this->assertSame('fallback', $config->get('does.not.exist', 'fallback'));
  }

  /**
   * Quirk of the !== $default check: if an env var literally equals the
   * caller-supplied default, the code treats it as "not found" and falls
   * through to the JSON value. Pinning this behavior so a future change
   * to the sentinel logic is intentional.
   */
  public function testEnvValueEqualToDefaultFallsThroughToJson(): void
  {
    $this->setEnv('APPLICATION_CODE', 'from-generic-env');
    $config = $this->makeConfig();

    // Default matches the env var value -> env lookup is treated as "miss"
    // and the JSON value is returned instead.
    $this->assertSame('from-json', $config->get('application.code', 'from-generic-env'));
  }

  /**
   * The config() helper must pass $env through to Config::get().
   */
  public function testConfigHelperRespectsEnvFlag(): void
  {
    global $app;

    // Sanity: bootstrap created an Application; helper relies on $app.
    $this->assertNotNull($app);

    $this->setEnv('APPLICATION_NAME', 'from-env');

    // With $env=true (default) the env override applies.
    $this->assertSame('from-env', \config('application.name'));

    // With $env=false the env override is bypassed.
    // The exact JSON-side value depends on the app's loaded config; we
    // only assert that it's NOT the env value.
    $this->assertNotSame('from-env', \config('application.name', null, false));
  }
}
