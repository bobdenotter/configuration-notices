<?php

declare(strict_types=1);

namespace BobdenOtter\ConfigurationNotices;

use Bolt\Configuration\Config;
use Bolt\Extension\BaseExtension;
use Bolt\Repository\FieldRepository;
use ComposerPackages\Packages;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Tightenco\Collect\Support\Collection;

class Checks
{
    protected $defaultDomainPartials = ['.dev', 'dev.', 'devel.', 'development.', 'test.', '.test', 'new.', '.new', '.local', 'local.', '.wip', 'localhost'];

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

    /** @var FieldRepository */
    private $fieldRepository;

    private $levels = [
        1 => 'info',
        2 => 'warning',
        3 => 'danger',
    ];

    private $generalForbiddenFieldNames = [
        'id',
        'definitionfromcontenttypeconfig',
        'twig',
        'definiiton',
        'icon',
        'author',
        'status',
        'createdat',
        'modifiedat',
        'publishedat',
        'depublishedat',
        'fields',
        'field',
        'statuses',
        'authorname',
        'taxonomies',
        'array',
    ];

    private $setForbiddenFieldNames = [
        'id',
        'definition',
        'name',
        'apivalue',
        'value',
        'defaultvalue',
        'new',
        'parsedvalue',
        'twigvalue',
        'sortorder',
        'locale',
        'version',
        'parent',
        'label',
        'type',
        'contentselect',
    ];

    private $client = null;

    public function __construct(BaseExtension $extension)
    {
        $this->boltConfig = $extension->getBoltConfig();
        $this->request = $extension->getRequest();
        $this->extensionConfig = $extension->getConfig();
        $this->container = $extension->getContainer();
        $this->extension = $extension;
        $this->fieldRepository = $extension->getService(FieldRepository::class);
    }

    public function getResults(): ?array
    {
        if (! $this->isReady()) {
            return null;
        }

        $this->liveCheck();
        $this->envCheck();
        $this->newContentTypeCheck();
        $this->slugUsesCheck();
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
        $this->checkDoctrineMissingJsonGetText();
        $this->forbiddenFieldNamesCheck();
        $this->checkInferredSlug();
        $this->unauthorizedThemeFilesCheck();

        return [
            'severity' => $this->severity,
            'notices' => $this->notices,
        ];
    }

    /**
     * We check if a common field 'text' actually "exists". If it doesn't, we're
     * too early in the Request/Response cycle, and we bail out, so we can try
     * running it again later.
     * (Because all widgets are run twice, for some obscure reason)
     */
    private function isReady(): ?string
    {
        return $this->fieldRepository::getFieldClassname('text');
    }

    /**
     * Check whether the site is live or not.
     */
    private function liveCheck(): void
    {
        if ($this->getParameter('kernel.environment') === 'prod' && $this->getParameter('kernel.debug') !== true) {
            return;
        }

        if ($this->onLocalUrl()) {
            return;
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
     * Check whether the configured APP_ENV is valid.
     */
    private function envCheck(): void
    {
        if (in_array($this->getParameter('kernel.environment'), ['prod', 'dev', 'test'])) {
            return;
        }

        $this->setNotice(
            2,
            'Bolt supports three different modes for <code>APP_ENV</code>: <code>dev</code>, 
                <code>prod</code> and <code>test</code>. You should only use one of these three.',
            "The current configured <code>APP_ENV</code> is <code>" .
                $this->getParameter('kernel.environment') . "</code>. Make sure you've used lowercase in 
                your configured environment."
        );
    }

    private function onLocalUrl(): bool
    {
        $host = parse_url($this->request->getSchemeAndHttpHost());

        // If we have an IP-address, we assume it's "dev" / local
        if (filter_var($host['host'], FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        $domainPartials = array_unique(array_merge(
            $this->extensionConfig->get('local_domains'),
            $this->defaultDomainPartials
        ));

        foreach ($domainPartials as $partial) {
            if (mb_strpos($host['host'], $partial) !== false) {
                return true;
            }
        }

        return false;
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
     * Check if the ContentType's Slug field's "uses" isn't set to a non-existing field
     */
    private function slugUsesCheck(): void
    {
        $info = 'Make sure to define the <code>uses:</code> attribute of the <code>slug</code>. It should refer to existing Fields.';

        foreach ($this->boltConfig->get('contenttypes') as $contentType) {
            $fields = $contentType->get('fields');
            $slugs = $fields->filter(function (Collection $field) {
                return $field->get('type') === 'slug';
            });

            foreach ($slugs as $name => $slug) {
                if (! $slug->has('uses')) {
                    $notice = sprintf("The <b>ContentType %s</b> has a slug field '%s', which does not define the <code>uses</code> attribute.", $contentType->get('name'), $name);
                    $this->setNotice(2, $notice, $info);

                    // apply continue to nested loop.
                    continue 2;
                }

                foreach ($slug->get('uses')->all() as $fieldName) {
                    if (! $fields->has($fieldName)) {
                        $notice = sprintf('The <b>ContentType %s</b> has an incorrectly defined <code>slug</code>. It refers to <code>%s</code>, but there is no such Field defined.', $contentType->get('name'), $fieldName);
                        $this->setNotice(2, $notice, $info);

                        // apply continue to nested loop.
                        continue 2;
                    }
                }
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
                if (! $this->fieldRepository::getFieldClassname($fieldType->get('type'))) {
                    $notice = sprintf("A field of type <code>%s</code> was added to the '%s' ContentType, but this is not a valid field type.", $fieldType->get('type'), $contentType->get('name'));
                    $info = sprintf('Edit your <code>contenttypes.yaml</code> to ensure that the <code>%s/%s</code> field has a valid type.', $contentType->get('slug'), $fieldType->get('type'));

                    $this->setNotice(1, $notice, $info);
                }
            }
        }
    }

    private function forbiddenFieldNamesCheck(): void
    {
        foreach ($this->boltConfig->get('contenttypes') as $contentType) {
            $fields = $contentType->get('fields')->toArray();

            foreach ($fields as $name => $field) {
                $this->checkFieldName($name, $field, $contentType->get('slug'));
            }
        }
    }

    private function checkInferredSlug(): void
    {
        foreach ($this->boltConfig->get('contenttypes') as $contentType) {
            if (! empty($contentType->get('inferred_slug'))) {
                $notice = sprintf(
                    'There is an ambiguity in the <code>slug</code> of the <strong>%s</strong> ContentType: It can be either <code>%s</code> or <code>%s</code>.',
                    $contentType->get('name'),
                    $contentType->get('inferred_slug')[0],
                    $contentType->get('inferred_slug')[1]
                );
                $info = sprintf(
                    'You should either make the ContentType\'s key and its <code>name</code>-field consistent, or explicitly define the <code>slug</code> as you\'d like to reference this ContentType. For now, Bolt will use <code>%s</code>',
                    $contentType->get('slug')
                );

                $this->setNotice(2, $notice, $info);
            }
        }
    }

    private function checkFieldName(string $name, array $field, string $ct): void
    {
        if ($field['type'] === 'set') {
            if (in_array(mb_strtolower($name), $this->setForbiddenFieldNames, true)) {
                $notice = sprintf('A Set field in <strong>%s</strong> has a name <code>%s</code>. You may not be able to access a field with that name in Twig, because it is a reserved Bolt word.', $ct, $name);
                $info = sprintf('You should not use a <code>%s</code> field inside a set. Please rename it.', $name);

                $this->setNotice(2, $notice, $info);
            }

            foreach ($field['fields'] as $subname => $subfield) {
                $this->checkFieldName($subname, $subfield, $ct);
            }
        } elseif ($field['type'] === 'collection') {
            foreach ($field['fields'] as $subname => $subfield) {
                $this->checkFieldName($subname, $subfield, $ct);
            }
        } else {
            // Any other field.
            if (in_array(mb_strtolower($name), $this->generalForbiddenFieldNames, true)) {
                $notice = sprintf('A field with name <code>%s</code> was found inside the <strong>%s</strong> ContentType. You may not be able to access a field with that name in Twig.', $name, $ct);
                $this->setNotice(2, $notice, '');
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
            foreach ($noLocalesCTs as $contentType => $fields) {
                $notice = sprintf('The <code>localize: true</code> option is set for field(s) <code>%s</code>, but their ContentType <code>%s</code> has no locales set.', implode(' ,', $fields), $contentType);
                $info = sprintf('Make sure to add the <code>locales</code> option with the enabled languages to the <code>%s</code> ContentType.', $contentType);
                $this->setNotice(2, $notice, $info);
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
     * Check if server configuration forbids access to /theme/{the_theme_name}/configtester_access.twig
     */
    private function unauthorizedThemeFilesCheck(): void
    {
        // We don't perform this check if it's disabled in config (default to false),
        // or if we're in a local development environment
        if ($this->onLocalUrl() || ! array_key_exists('theme_folder_access', (array) $this->extensionConfig->get('checks')) || ! $this->extensionConfig->get('checks')['theme_folder_access']) {
            return;
        }

        $fileName = '/configtester_access.twig';

        $url = 'theme/' . $this->boltConfig->get('general/theme') . $fileName;

        if ($this->isWritable('theme', $fileName, true) && $this->isReachable($url)) {
            $notice = 'Twig files in the theme folder are accessible publicly, but best practice is to forbid direct access to such files in your theme.';
            $info = 'Check the <a target="_blank" href="https://docs.bolt.cm/4.0/installation/webserver/apache#htaccess-update-for-bolt-versions-lower-than-4-1-13">';
            $info .= 'webserver configuration documentation for Apache"</a> or <a href="https://docs.bolt.cm/4.0/installation/webserver/nginx" target="_blank">Nginx</a> to fix this vulnerability.';

            $this->setNotice(3, $notice, $info);
        }

        // Delete the file.
        $this->isWritable('theme', $fileName);
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

    /**
     * Checks if a BC introduced in 4.1 in doctrine.yaml is fixed (manually).
     */
    private function checkDoctrineMissingJsonGetText(): void
    {
        $projectDir = $this->container->get('kernel')->getprojectDir();
        $doctrine = Yaml::parseFile($projectDir . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'packages' . DIRECTORY_SEPARATOR . 'doctrine.yaml');

        // Ensure it doesn't break, if there are multiple entity managers causing a different configuration structure
        $functions = array_key_exists('dql', $doctrine['doctrine']['orm']) ?
            $doctrine['doctrine']['orm']['dql']['string_functions'] : $doctrine['doctrine']['orm']['entity_managers']['default']['dql']['string_functions'];


        if (! array_key_exists('JSON_GET_TEXT', $functions)) {
            $notice = 'The <code>JSON_TEXT_FUNCTION</code> is missing from your <code>config/packages/doctrine.yaml</code> definition.';
            $info = 'To resolve this, modify your <code>doctrine.yaml</code> file according to the changes on the ';
            $info .= "<a href='https://github.com/bolt/project/pull/35/files'>bolt/project</a> repository.";

            $this->setNotice(3, $notice, $info);
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

        // We split the string below, so ECS doesn't "helpfully" substitute it for the classname.
        return mb_strpos($file, 'Symfony\Compo' . 'nent\Debug\Debug') !== false;
    }

    private function isWritable($fileSystem, $filename, bool $keep = false): bool
    {
        $filePath = $this->boltConfig->getPath($fileSystem) . $filename;
        $filesystem = new Filesystem();

        try {
            $filesystem->dumpFile($filePath, 'ok');
            if (! $keep) {
                $filesystem->remove($filePath);
            }
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    private function isReachable(string $relativeUrl)
    {
        if (! $this->client) {
            $this->client = HttpClient::create();
        }

        $url = $this->container->get('router')->generate('homepage', [], RouterInterface::ABSOLUTE_URL) . $relativeUrl;
        $response = $this->client->request('GET', $url);

        try {
            return $response->getStatusCode() === Response::HTTP_OK;
        } catch (TransportExceptionInterface $e) {
        }

        //  ¯\_(ツ)_/¯
        return false;
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
