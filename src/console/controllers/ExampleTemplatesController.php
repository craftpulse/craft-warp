<?php
/**
 * Warp plugin for Craft CMS 5.x
 *
 * Passwordless front-end auth for Craft members. Magic links, email codes, and passkeys.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\warp\console\controllers;

use Craft;
use craft\helpers\FileHelper;
use craftpulse\warp\Warp;
use Throwable;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Installs Warp's example member-area templates into the project's templates directory.
 *
 * The same convenience Craft Commerce ships as `commerce/example-templates`. Run:
 *
 * ```
 * php craft warp/example-templates
 * ```
 *
 * The bundle is copied to `templates/<folder>` (default `members`). Choosing
 * a different folder name rewrites the bundle's internal `members/...`
 * template paths and URLs to match, so the copy works wherever it lands.
 *
 * @author CraftPulse
 * @since 5.0.0
 */
class ExampleTemplatesController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var string|null The name of the target folder under `templates/` the
     * bundle is copied to. Prompted for when omitted; defaults to `members`.
     *
     * @since 5.0.0
     */
    public ?string $folderName = null;

    /**
     * @var bool Whether to overwrite an existing folder. Must be passed when
     * a folder with the chosen name already exists.
     *
     * @since 5.0.0
     */
    public bool $overwrite = false;

    /**
     * @inheritdoc
     */
    public $defaultAction = 'install';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @return array<int, string>
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'folderName';
        $options[] = 'overwrite';

        return $options;
    }

    /**
     * Copies the example member-area templates into the project's templates
     * directory.
     *
     * @return int a `yii\console\ExitCode` value
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    public function actionInstall(): int
    {
        $source = FileHelper::normalizePath(
            dirname((string)Warp::$plugin->getBasePath()) . DIRECTORY_SEPARATOR . 'example-templates' . DIRECTORY_SEPARATOR . 'members',
        );

        if (!is_dir($source)) {
            $this->stderr("The example templates were not found at {$source}." . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $folderName = $this->folderName;

        if ($folderName === null) {
            $this->stdout('The example templates will be copied into your templates directory.' . PHP_EOL);
            $folderName = (string)$this->prompt('Choose a folder name:', ['required' => true, 'default' => 'members']);
        }

        $folderName = trim($folderName, "/ \t\n\r");

        if ($folderName === '' || !preg_match('/^[a-zA-Z0-9_\-\/]+$/', $folderName)) {
            $this->stderr('The folder name may only contain letters, numbers, underscores, hyphens, and slashes.' . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $destination = FileHelper::normalizePath(
            Craft::$app->getPath()->getSiteTemplatesPath() . DIRECTORY_SEPARATOR . $folderName,
        );

        if (is_dir($destination) && !$this->overwrite) {
            $this->stderr("The folder {$destination} already exists. Pass --overwrite to replace it." . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        try {
            $this->_copy($source, $destination, $folderName);
        } catch (Throwable $e) {
            $this->stderr('Could not install the example templates: ' . $e->getMessage() . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("The example templates were installed at {$destination}." . PHP_EOL, Console::FG_GREEN);
        $this->stdout(PHP_EOL . 'Next step: point the loginPath general config setting at your login page,' . PHP_EOL);
        $this->stdout("for example ->loginPath('{$folderName}/login') in config/general.php." . PHP_EOL);

        return ExitCode::OK;
    }

    // Private Methods
    // =========================================================================

    /**
     * Copies the bundle into place, rewriting its internal `members/...`
     * template paths and URLs when a different folder name was chosen. The
     * rewrite happens in a temp copy first, so a failure never leaves a
     * half-rewritten destination.
     *
     * @param string $source the bundled example-templates path
     * @param string $destination the target folder under the templates path
     * @param string $folderName the chosen folder name
     * @throws \yii\base\Exception if a directory cannot be created
     * @throws \yii\base\ErrorException if a template cannot be rewritten
     *
     * @author CraftPulse
     * @since 5.0.0
     */
    private function _copy(string $source, string $destination, string $folderName): void
    {
        $temp = Craft::$app->getPath()->getTempPath()
            . DIRECTORY_SEPARATOR . 'warp-example-templates-' . md5(uniqid((string)mt_rand(), true));

        FileHelper::copyDirectory($source, $temp);

        // The bundle's paths and URLs are root-relative to its canonical
        // folder name; a rename rewrites every quoted 'members/... reference.
        if ($folderName !== 'members') {
            foreach (FileHelper::findFiles($temp, ['only' => ['*.twig']]) as $file) {
                $contents = (string)file_get_contents($file);
                FileHelper::writeToFile($file, str_replace("'members/", "'{$folderName}/", $contents));
            }
        }

        if (is_dir($destination)) {
            FileHelper::removeDirectory($destination);
        }

        FileHelper::copyDirectory($temp, $destination);
        FileHelper::removeDirectory($temp);
    }
}
