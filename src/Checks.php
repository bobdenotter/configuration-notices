<?php

declare(strict_types=1);

namespace BobdenOtter\ConfigurationNotices;

use Bolt\Canonical;
use Bolt\Configuration\Config;
use Bolt\Extension\BaseExtension;
use ComposerPackages\Packages;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;
use Tightenco\Collect\Support\Collection;

class Checks
{
    protected $defaultDomainPartials = ['.dev', 'dev.', 'devel.', 'development.', 'test.', '.test', 'new.', '.new', '.local', 'local.', '.wip'];

    /** @var Config */
    private $boltConfig;

    /** @var Request */
    private $request;

    /** @var Collection */
    private $extensionConfig;

    private $notices = [];
    private $severity = 0;

    /** @var Container */
    private $container;

    /** @var BaseExtension */
    private $extension;

    private $levels = [
        1 => 'info',
        2 => 'warning',
        3 => 'danger',
    ];

    public function __construct(BaseExtension $extension)
    {
        $this->boltConfig = $extension->getBoltConfig();
        $this->request = $extension->getRequest();
        $this->extensionConfig = $extension->getConfig();
        $this->container = $extension->getContainer();
        $this->extension = $extension;
    }

    public function getResults(): array
    {
        $this->liveCheck();
        $this->newContentTypeCheck();
        $this->fieldTypesCheck();
        $this->localizedFieldsAndContentLocalesCheck();
        $this->duplicateTaxonomyAndContentTypeCheck();
        $this->singleHostnameCheck();
        $this->ipAddressCheck();
        $this->topLevelCheck();
        $this->writableFolderCheck();
        $this->thumbsFolderCheck();
        $this->canonicalCheck();
        $this->imageFunctionsCheck();
        $this->maintenanceCheck();
        $this->servicesCheck();
        $this->symfonyVersionCheck();
        $this->checkDeprecatedDebug();

        return [
            'severity' => $this->severity,
            'notices' => $this->notices,
        ];
    }

    /**
     * Check whether the site is live or not.
     */
    private function liveCheck(): void
    {
        if ($this->getParameter('kernel.environment') === 'prod' && $this->getParameter('kernel.debug') !== true) {
            return;
        }

        $host = parse_url($this->request->getSchemeAndHttpHost());

        // If we have an IP-address, we assume it's "dev"
        if (filter_var($host['host'], FILTER_VALIDATE_IP) !== false) {
            return;
        }

        $domainPartials = array_unique(array_merge(
            $this->extensionConfig->get('local_domains'),
            $this->defaultDomainPartials
        ));

        foreach ($domainPartials as $partial) {
            if (mb_strpos($host['host'], $partial) !== false) {
                return;
            }
        }

        $this->setNotice(
            2,
            'It seems like this website is running on a <strong>non-development environment</strong>,
             while development mode is enabled (<code>APP_ENV=dev</code> and/or <code>APP_DEBUG=1</code>).
             Ensure debug is disabled in production environments, otherwise it will
             result in an extremely large <code>var/cache</code> folder and a measurable reduced
             performance.',
            "If you wish to hide this message, add a key to your <abbr title='config/extensions/bobdenotter-configurationnotices.yaml'>
             config <code>yaml</code></abbr> file with a (partial) domain name in it, that should be
             seen as a development environment: <code>local_domains: [ '.foo' ]</code>."
        );
    }

    /**
     * Check whether ContentTypes have been added without flushing the cache afterwards
     */
    private function newContentTypeCheck(): void
    {
        $fromParameters = explode('|', $this->getParameter('bolt.requirement.contenttypes'));

        foreach ($this->boltConfig->get('contenttypes') as $contentType) {
            if (! in_array($contentType->get('slug'), $fromParameters, true)) {
                $notice = sprintf("A <b>new ContentType</b> ('%s') was added. Make sure to <a href='./clearcache'>clear the cache</a>, so it shows up correctly.", $contentType->get('name'));
                $info = "By clearing the cache, you'll ensure the routing requirements are updated, allowing Bolt to generate the correct links to the new ContentType.";

                $this->setNotice(3, $notice, $info);

                return;
            }
        }
    }

    /**
     * Check if a field has a non-existing type
     */
    private function fieldTypesCheck(): void
    {
        foreach ($this->boltConfig->get('contenttypes') as $contentType) {
            foreach ($contentType->get('fields') as $fieldType) {
                if (! class_exists('\\Bolt\\Entity\\Field\\' . ucwords($fieldType->get('type')) . 'Field')) {
                    $notice = sprintf("A field of type <code>%s</code> was added to the '%s' ContentType, but this is not a valid field type.", $fieldType->get('type'), $contentType->get('name'));
                    $info = sprintf('Edit your <code>contenttypes.yaml</code> to ensure that the <code>%s/%s</code> field has a valid type.', $contentType->get('slug'), $fieldType->get('type'));

                    $this->setNotice(1, $notice, $info);
                }
            }
        }
    }

    private function localizedFieldsAndContentLocalesCheck(): void
    {
        $noLocalesCTs = [];
        $noLocalizedFieldsCTs = [];
        foreach ($this->boltConfig->get('contenttypes') as $contentType) {
            $contentLocales = $contentType->get('locales', [])->toArray();
            $localizedFields = $contentType->get('fields')->where('localize', true)->toArray();

            if (empty($contentLocales) && ! empty($localizedFields)) {
                $noLocalesCTs[$contentType->get('name')] = array_keys($localizedFields);
            }

            if (! empty($contentLocales) && empty($localizedFields)) {
                $noLocalizedFieldsCTs[] = $contentType->get('name');
            }
        }

        if (! empty($noLocalizedFieldsCTs)) {
            $notice = sprintf('The <code>locales</code> option is set on ContentType(s) <code>%s</code>, but no fields are localized.', implode(', ', $noLocalizedFieldsCTs));
            $info = 'Make sure to update your <code>contenttypes.yaml</code> by removing the <code>locales</code> option <b>or</b> by adding <code>localize: true</code> to fields that can be translated.';
            $this->setNotice(1, $notice, $info);
        }

        if (! empty($noLocalesCTs)) {
            $this->setSeverity(2);
            foreach ($noLocalesCTs as $contentType => $fields) {
                $notice = sprintf('The <code>localize: true</code> option is set for field(s) <code>%s</code>, but their ContentType <code>%s</code> has no locales set.', implode(' ,', $fields), $contentType);
                $info = sprintf('Make sure to add the <code>locales</code> option with the enabled languages to the <code>%s</code> ContentType.', $contentType);
                $this->setNotice($notice, $info);
            }
        }
    }

    /**
     * Check whether there is a ContentType and Taxonomy with the same name, because that will confuse routing
     */
    private function duplicateTaxonomyAndContentTypeCheck(): void
    {
        $configContent = $this->boltConfig->get('contenttypes');
        $configTaxo = $this->boltConfig->get('taxonomies');

        $contenttypes = collect($configContent->pluck('slug'))->merge($configContent->pluck('singular_slug'))->unique();
        $taxonomies = collect($configTaxo->pluck('slug'))->merge($configTaxo->pluck('singular_slug'))->unique();

        $overlap = $contenttypes->intersect($taxonomies);

        if ($overlap->isNotEmpty()) {
            $notice = sprintf('The ContentTypes and Taxonomies contain <strong>overlapping identifiers</strong>: <code>%s</code>.', $overlap->implode('</code>, <code>'));
            $info = 'Edit your <code>contenttypes.yaml</code> or your <code>taxonomies.yaml</code>, to ensure that all the used <code>slug</code>s and <code>singular_slug</code>s are unique.';

            $this->setNotice(2, $notice, $info);
        }
    }

    /**
     * Check whether or not we're running on a hostname without TLD, like 'http://localhost'.
     */
    private function singleHostnameCheck(): void
    {
        $hostname = $this->request->getHttpHost();

        if (mb_strpos($hostname, '.') === false) {
            $notice = "You are using <code>${hostname}</code> as host name. Some browsers have problems with sessions on hostnames that do not have a <code>.tld</code> in them.";
            $info = 'If you experience difficulties logging on, either configure your webserver to use a hostname with a dot in it, or use another browser.';

            $this->setNotice(1, $notice, $info);
        }
    }

    /**
     * Check whether or not we're running on a hostname without TLD, like 'http://localhost'.
     */
    private function ipAddressCheck(): void
    {
        $hostname = $this->request->getHttpHost();

        if (filter_var($hostname, FILTER_VALIDATE_IP)) {
            $notice = "You are using the <strong>IP address</strong> <code>${hostname}</code> as host name. This is known to cause problems with sessions on certain browsers.";
            $info = 'If you experience difficulties logging on, either configure your webserver to use a proper hostname, or use another browser.';

            $this->setNotice(1, $notice, $info);
        }
    }

    /**
     * Ensure we're running in the webroot, and not in a subfolder
     */
    private function topLevelCheck(): void
    {
        $base = $this->request->getBaseUrl();

        if (! empty($base)) {
            $notice = 'You are using Bolt in a subfolder, <strong>instead of the webroot</strong>.';
            $info = "It is recommended to use Bolt from the 'web root', so that it is in the top level. If you wish to
                use Bolt for only part of a website, we recommend setting up a subdomain like <code>news.example.org</code>.";

            $this->setNotice(1, $notice, $info);
        }
    }

    /**
     * Check if some common file locations are writable.
     */
    private function writableFolderCheck(): void
    {
        $fileName = '/configtester_' . date('Y-m-d-h-i-s') . '.txt';
        $fileSystems = ['files', 'config', 'cache'];

        if ($this->getParameter('env(DATABASE_DRIVER)') === 'pdo_sqlite') {
            $fileSystems[] = 'database';
        }

        foreach ($fileSystems as $fileSystem) {
            if (! $this->isWritable($fileSystem, $fileName)) {
                $baseName = $this->boltConfig->getPath('root');
                $folderName = str_replace($baseName, '…', $this->boltConfig->getPath($fileSystem));
                $notice = 'Bolt needs to be able to <strong>write files to</strong> the "' . $fileSystem . '" folder, but it doesn\'t seem to be writable.';
                $info = 'Make sure the folder <code>' . $folderName . '</code> exists, and is writable to the webserver.';

                $this->setNotice(2, $notice, $info);
            }
        }
    }

    /**
     * Check if the thumbs/ folder is writable, if `save_files: true`
     */
    private function thumbsFolderCheck(): void
    {
        if (! $this->boltConfig->get('general/thumbnails/save_files')) {
            return;
        }

        $fileName = '/configtester_' . date('Y-m-d-h-i-s') . '.txt';

        if (! $this->isWritable('thumbs', $fileName)) {
            $notice = "Bolt is configured to save thumbnails to disk for performance, but the <code>thumbs/</code> folder doesn't seem to be writable.";
            $info = 'Make sure the folder exists, and is writable to the webserver.';

            $this->setNotice(2, $notice, $info);
        }
    }

    /**
     * Check if the current url matches the canonical.
     */
    private function canonicalCheck(): void
    {
        $hostname = parse_url(strtok($this->request->getUri(), '?'));

        if ($hostname['scheme'] !== $_SERVER['CANONICAL_SCHEME'] || $hostname['host'] !== $_SERVER['CANONICAL_HOST']) {
            $canonical = sprintf('%s://%s', $_SERVER['CANONICAL_SCHEME'], $_SERVER['CANONICAL_HOST']);
            $login = sprintf('%s%s', $canonical, $this->getParameter('bolt.backend_url'));
            $notice = "The <strong>canonical hostname</strong> is set to <code>${canonical}</code> in <code>config.yaml</code>,
                but you are currently logged in using another hostname. This might cause issues with uploaded files, or
                links inserted in the content.";
            $info = sprintf(
                "Log in on Bolt using the proper URL: <code><a href='%s'>%s</a></code>.",
                $login,
                $login
            );

            $this->setNotice(1, $notice, $info);
        }
    }

    /**
     * Check if the exif, fileinfo and gd extensions are enabled / compiled into PHP.
     */
    private function imageFunctionsCheck(): void
    {
        if (! extension_loaded('exif') || ! function_exists('exif_read_data')) {
            $notice = 'The function <code>exif_read_data</code> does not exist, which means that Bolt can not create thumbnail images.';
            $info = "Make sure the <code>php-exif</code> extension is enabled <u>and</u> compiled into your PHP setup. See <a href='http://php.net/manual/en/exif.installation.php'>here</a>.";

            $this->setNotice(1, $notice, $info);
        }

        if (! extension_loaded('fileinfo') || ! class_exists('finfo')) {
            $notice = 'The class <code>finfo</code> does not exist, which means that Bolt can not create thumbnail images.';
            $info = "Make sure the <code>fileinfo</code> extension is enabled <u>and</u> compiled into your PHP setup. See <a href='http://php.net/manual/en/fileinfo.installation.php'>here</a>.";

            $this->setNotice(1, $notice, $info);
        }

        if (! extension_loaded('gd') || ! function_exists('gd_info')) {
            $notice = 'The function <code>gd_info</code> does not exist, which means that Bolt can not create thumbnail images.';
            $info = "Make sure the <code>gd</code> extension is enabled <u>and</u> compiled into your PHP setup. See <a href='http://php.net/manual/en/image.installation.php'>here</a>.";

            $this->setNotice(1, $notice, $info);
        }
    }

    /**
     * If the site is in maintenance mode, show this on the dashboard.
     */
    protected function maintenanceCheck(): void
    {
        if ($this->boltConfig->get('general/maintenance_mode', false)) {
            $notice = "Bolt's <strong>maintenance mode</strong> is enabled. This means that non-authenticated users will not be able to see the website.";
            $info = 'To make the site available to the general public again, set <code>maintenance_mode: false</code> in your <code>config.yaml</code> file.';

            $this->setNotice(1, $notice, $info);
        }
    }

    private function servicesCheck(): void
    {
        // This method is only available on 4.0.0 RC 21 and up.
        if (! method_exists($this->extension, 'getAllServiceNames')) {
            return;
        }

        $checkServices = Yaml::parseFile(dirname(__DIR__) . '/services.yaml');

        $availableServices = $this->extension->getAllServiceNames();

        foreach ($checkServices as $key => $service) {
            if (! $availableServices->contains($service['name'])) {
                $notice = "Bolt's <code>services.yaml</code> is missing the <code>${key}</code>. This needs to be added in order to function correctly.";
                $info = 'To remedy this, edit <code>services.yaml</code> in the <code>config</code> folder and add the following:';
                $info .= '<pre>' . $service['code'] . '</pre>';

                $this->setNotice(1, $notice, $info);
            }
        }
    }

    private function checkDeprecatedDebug(): void
    {
        if ($this->indexHasDeprecatedDebug()) {
            $filename = '…/' . basename($this->boltConfig->getPath('web')) . '/index.php';

            $notice = 'This site is using a deprecated Symfony error handler. To remedy this, edit <code>' . $filename . '</code> and replace:';
            $info = '<pre>use Symfony\Component\Debug\Debug;</pre>';
            $info .= 'With: ';
            $info .= '<pre>use Symfony\Component\ErrorHandler\Debug;</pre>';

            $this->setNotice(2, $notice, $info);
        }
    }

    private function symfonyVersionCheck(): void
    {
        // Leave early, because we only want to show this if the Deprecated Debug has been solved first.
        if ($this->indexHasDeprecatedDebug()) {
            return;
        }
        $version = Packages::symfonyFrameworkBundle()->getVersion();

        if ($version < '5.0.0.0') {
            $code = '"extra": {
    "symfony": {
        "allow-contrib": true,
        "require": "^5.1"
    },';
            $notice = 'Bolt is currently running on Symfony 4. To bump the version to <strong>Symfony 5.1</strong>, edit <code>composer.json</code> in the project root folder and set the following:';
            $info = '<pre>' . $code . '</pre>';
            $info .= 'Run <code>composer update</code> to do the upgrade to Symfony 5.1.';

            $this->setNotice(1, $notice, $info);
        }
    }

    private function indexHasDeprecatedDebug(): bool
    {
        $filename = $this->boltConfig->getPath('web') . '/index.php';

        $file = file_get_contents($filename);

        return mb_strpos($file, 'Symfony\Component\Debug\Debug') !== false;
    }

    private function isWritable($fileSystem, $filename): bool
    {
        $filePath = $this->boltConfig->getPath($fileSystem) . $filename;
        $filesystem = new Filesystem();

        try {
            $filesystem->dumpFile($filePath, 'ok');
            $filesystem->remove($filePath);
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    private function setSeverity(int $severity): void
    {
        $this->severity = max($severity, $this->severity);
    }

    private function setNotice(int $severity, string $notice, ?string $info = null): void
    {
        $this->setSeverity($severity);

        $this->notices[] = [
            'severity' => $this->levels[$severity],
            'notice' => $notice,
            'info' => $info,
        ];
    }

    /**
     * @return string|bool|null
     */
    private function getParameter(string $parameter)
    {
        return $this->container->getParameter($parameter);
    }
}
