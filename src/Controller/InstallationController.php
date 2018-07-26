<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\InstallationBundle\Controller;

use Contao\Environment;
use Contao\InstallationBundle\Config\ParameterDumper;
use Contao\InstallationBundle\Database\AbstractVersionUpdate;
use Contao\InstallationBundle\Database\ConnectionFactory;
use Contao\InstallationBundle\Event\ContaoInstallationEvents;
use Contao\InstallationBundle\Event\InitializeApplicationEvent;
use Doctrine\DBAL\DBALException;
use Patchwork\Utf8;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @Route("/contao", defaults={"_scope" = "backend", "_token_check" = true})
 */
class InstallationController implements ContainerAwareInterface
{
    use ContainerAwareTrait;

    /**
     * @var array
     */
    private $context = [
        'has_admin' => false,
        'hide_admin' => false,
        'sql_message' => '',
    ];

    /**
     * @Route("/install", name="contao_install")
     */
    public function installAction(): Response
    {
        if (null !== ($response = $this->initializeApplication())) {
            return $response;
        }

        if ($this->container->has('contao.framework')) {
            $this->container->get('contao.framework')->initialize();
        }

        $installTool = $this->container->get('contao.install_tool');

        if ($installTool->isLocked()) {
            return $this->render('locked.html.twig');
        }

        if (!$installTool->canWriteFiles()) {
            return $this->render('not_writable.html.twig');
        }

        if ($installTool->shouldAcceptLicense()) {
            return $this->acceptLicense();
        }

        if ('' === $installTool->getConfig('installPassword')) {
            return $this->setPassword();
        }

        if (!$this->container->get('contao.install_tool_user')->isAuthenticated()) {
            return $this->login();
        }

        if (!$installTool->canConnectToDatabase($this->getContainerParameter('database_name'))) {
            return $this->setUpDatabaseConnection();
        }

        $this->warmUpSymfonyCache();

        if ($installTool->hasOldDatabase()) {
            return $this->render('old_database.html.twig');
        }

        if ($installTool->hasConfigurationError($this->context)) {
            return $this->render('configuration_error.html.twig');
        }

        $this->runDatabaseUpdates();

        if (null !== ($response = $this->adjustDatabaseTables())) {
            return $response;
        }

        if (null !== ($response = $this->importExampleWebsite())) {
            return $response;
        }

        if (null !== ($response = $this->createAdminUser())) {
            return $response;
        }

        return $this->render('main.html.twig', $this->context);
    }

    private function initializeApplication(): ?Response
    {
        $event = new InitializeApplicationEvent();

        $this->container->get('event_dispatcher')->dispatch(ContaoInstallationEvents::INITIALIZE_APPLICATION, $event);

        if ($event->hasOutput()) {
            return $this->render('initialize.html.twig', [
                'output' => $event->getOutput(),
            ]);
        }

        return null;
    }

    /**
     * Renders a form to accept the license.
     *
     * @throws \RuntimeException
     *
     * @return Response|RedirectResponse
     */
    private function acceptLicense(): Response
    {
        $request = $this->container->get('request_stack')->getCurrentRequest();

        if (null === $request) {
            throw new \RuntimeException('The request stack did not contain a request');
        }

        if ('tl_license' !== $request->request->get('FORM_SUBMIT')) {
            return $this->render('license.html.twig');
        }

        $this->container->get('contao.install_tool')->persistConfig('licenseAccepted', true);

        return $this->getRedirectResponse();
    }

    /**
     * Renders a form to set the install tool password.
     *
     * @throws \RuntimeException
     *
     * @return Response|RedirectResponse
     */
    private function setPassword(): Response
    {
        $request = $this->container->get('request_stack')->getCurrentRequest();

        if (null === $request) {
            throw new \RuntimeException('The request stack did not contain a request');
        }

        if ('tl_password' !== $request->request->get('FORM_SUBMIT')) {
            return $this->render('password.html.twig');
        }

        $password = $request->request->get('password');
        $confirmation = $request->request->get('confirmation');

        // The passwords do not match
        if ($password !== $confirmation) {
            return $this->render('password.html.twig', [
                'error' => $this->trans('password_confirmation_mismatch'),
            ]);
        }

        $installTool = $this->container->get('contao.install_tool');
        $minlength = $installTool->getConfig('minPasswordLength');

        // The passwords is too short
        if (Utf8::strlen($password) < $minlength) {
            return $this->render('password.html.twig', [
                'error' => sprintf($this->trans('password_too_short'), $minlength),
            ]);
        }

        $installTool->persistConfig('installPassword', password_hash($password, PASSWORD_DEFAULT));
        $this->container->get('contao.install_tool_user')->setAuthenticated(true);

        return $this->getRedirectResponse();
    }

    /**
     * Renders a form to log in.
     *
     * @throws \RuntimeException
     *
     * @return Response|RedirectResponse
     */
    private function login(): Response
    {
        $request = $this->container->get('request_stack')->getCurrentRequest();

        if (null === $request) {
            throw new \RuntimeException('The request stack did not contain a request');
        }

        if ('tl_login' !== $request->request->get('FORM_SUBMIT')) {
            return $this->render('login.html.twig');
        }

        $installTool = $this->container->get('contao.install_tool');

        $verified = password_verify(
            $request->request->get('password'),
            $installTool->getConfig('installPassword')
        );

        if (!$verified) {
            $installTool->increaseLoginCount();

            return $this->render('login.html.twig', [
                'error' => $this->trans('invalid_password'),
            ]);
        }

        $installTool->resetLoginCount();
        $this->container->get('contao.install_tool_user')->setAuthenticated(true);

        return $this->getRedirectResponse();
    }

    /**
     * The method preserves the container directory inside the cache folder,
     * because Symfony will throw a "compile error" exception if it is deleted
     * in the middle of a request.
     */
    private function purgeSymfonyCache(): void
    {
        $filesystem = new Filesystem();
        $cacheDir = $this->getContainerParameter('kernel.cache_dir');
        $ref = new \ReflectionObject($this->container);
        $containerDir = basename(\dirname($ref->getFileName()));

        /** @var SplFileInfo[] $finder */
        $finder = Finder::create()
            ->depth(0)
            ->exclude($containerDir)
            ->in($cacheDir)
        ;

        foreach ($finder as $file) {
            $filesystem->remove($file->getPathname());
        }

        if (\function_exists('opcache_reset')) {
            opcache_reset();
        }

        if (\function_exists('apc_clear_cache') && !ini_get('apc.stat')) {
            apc_clear_cache();
        }
    }

    /**
     * The method runs the optional cache warmers, because the cache will only
     * have the non-optional stuff at this time.
     */
    private function warmUpSymfonyCache(): void
    {
        $cacheDir = $this->getContainerParameter('kernel.cache_dir');

        if (file_exists($cacheDir.'/contao/config/config.php')) {
            return;
        }

        $warmer = $this->container->get('cache_warmer');

        if (!$this->getContainerParameter('kernel.debug')) {
            $warmer->enableOptionalWarmers();
        }

        $warmer->warmUp($cacheDir);

        if (\function_exists('opcache_reset')) {
            opcache_reset();
        }

        if (\function_exists('apc_clear_cache') && !ini_get('apc.stat')) {
            apc_clear_cache();
        }
    }

    /**
     * Renders a form to set up the database connection.
     *
     * @throws \RuntimeException
     *
     * @return Response|RedirectResponse
     */
    private function setUpDatabaseConnection(): Response
    {
        $request = $this->container->get('request_stack')->getCurrentRequest();

        if (null === $request) {
            throw new \RuntimeException('The request stack did not contain a request');
        }

        // Only warn the user if the connection fails and the env component is used
        if (false !== getenv('DATABASE_URL')) {
            return $this->render('misconfigured_database_url.html.twig');
        }

        $parameters = [
            'parameters' => [
                'database_host' => $this->getContainerParameter('database_host'),
                'database_port' => $this->getContainerParameter('database_port'),
                'database_user' => $this->getContainerParameter('database_user'),
                'database_password' => $this->getContainerParameter('database_password'),
                'database_name' => $this->getContainerParameter('database_name'),
            ],
        ];

        if ('tl_database_login' !== $request->request->get('FORM_SUBMIT')) {
            return $this->render('database.html.twig', $parameters);
        }

        $parameters = [
            'parameters' => [
                'database_host' => $request->request->get('dbHost'),
                'database_port' => $request->request->get('dbPort'),
                'database_user' => $request->request->get('dbUser'),
                'database_password' => $this->getContainerParameter('database_password'),
                'database_name' => $request->request->get('dbName'),
            ],
        ];

        if ('*****' !== $request->request->get('dbPassword')) {
            $parameters['parameters']['database_password'] = $request->request->get('dbPassword');
        }

        if (false !== strpos($parameters['parameters']['database_name'], '.')) {
            return $this->render('database.html.twig', array_merge(
                $parameters,
                ['database_error' => $this->trans('database_dot_in_dbname')]
            ));
        }

        $installTool = $this->container->get('contao.install_tool');
        $installTool->setConnection(ConnectionFactory::create($parameters));

        if (!$installTool->canConnectToDatabase($parameters['parameters']['database_name'])) {
            return $this->render('database.html.twig', array_merge(
                $parameters,
                ['database_error' => $this->trans('database_could_not_connect')]
            ));
        }

        $dumper = new ParameterDumper($this->getContainerParameter('kernel.root_dir'));
        $dumper->setParameters($parameters);
        $dumper->dump();

        $this->purgeSymfonyCache();

        return $this->getRedirectResponse();
    }

    private function runDatabaseUpdates(): void
    {
        if ($this->container->get('contao.install_tool')->isFreshInstallation()) {
            return;
        }

        /** @var SplFileInfo[] $finder */
        $finder = Finder::create()
            ->files()
            ->name('Version*Update.php')
            ->sortByName()
            ->in(__DIR__.'/../Database')
        ;

        $messages = [];

        foreach ($finder as $file) {
            $class = 'Contao\InstallationBundle\Database\\'.$file->getBasename('.php');

            /** @var AbstractVersionUpdate $update */
            $update = new $class($this->container->get('database_connection'));

            if ($update instanceof AbstractVersionUpdate) {
                $update->setContainer($this->container);

                if ($update->shouldBeRun()) {
                    $update->run();
                }

                if ($message = $update->getMessage()) {
                    $messages[] = $message;
                }
            }
        }

        $this->context['sql_message'] = implode($messages);
    }

    /**
     * Renders a form to adjust the database tables.
     *
     * @throws \RuntimeException
     */
    private function adjustDatabaseTables(): ?RedirectResponse
    {
        $this->container->get('contao.install_tool')->handleRunOnce();

        $installer = $this->container->get('contao.installer');

        $this->context['sql_form'] = $installer->getCommands();

        $request = $this->container->get('request_stack')->getCurrentRequest();

        if (null === $request) {
            throw new \RuntimeException('The request stack did not contain a request');
        }

        if ('tl_database_update' !== $request->request->get('FORM_SUBMIT')) {
            return null;
        }

        $sql = $request->request->get('sql');

        if (!empty($sql) && \is_array($sql)) {
            foreach ($sql as $hash) {
                $installer->execCommand($hash);
            }
        }

        return $this->getRedirectResponse();
    }

    /**
     * Renders a form to import the example website.
     *
     * @throws \RuntimeException
     */
    private function importExampleWebsite(): ?RedirectResponse
    {
        $installTool = $this->container->get('contao.install_tool');
        $templates = $installTool->getTemplates();

        $this->context['templates'] = $templates;

        if ($installTool->getConfig('exampleWebsite')) {
            $this->context['import_date'] = date('Y-m-d H:i', $installTool->getConfig('exampleWebsite'));
        }

        $request = $this->container->get('request_stack')->getCurrentRequest();

        if (null === $request) {
            throw new \RuntimeException('The request stack did not contain a request');
        }

        if ('tl_template_import' !== $request->request->get('FORM_SUBMIT')) {
            return null;
        }

        $template = $request->request->get('template');

        if ('' === $template || !\in_array($template, $templates, true)) {
            $this->context['import_error'] = $this->trans('import_empty_source');

            return null;
        }

        try {
            $installTool->importTemplate($template, '1' === $request->request->get('preserve'));
        } catch (DBALException $e) {
            $installTool->persistConfig('exampleWebsite', null);
            $installTool->logException($e);

            $this->context['import_error'] = $this->trans('import_exception');

            return null;
        }

        $installTool->persistConfig('exampleWebsite', time());

        return $this->getRedirectResponse();
    }

    /**
     * @throws \RuntimeException
     */
    private function createAdminUser(): ?RedirectResponse
    {
        $installTool = $this->container->get('contao.install_tool');

        if (!$installTool->hasTable('tl_user')) {
            $this->context['hide_admin'] = true;

            return null;
        }

        if ($installTool->hasAdminUser()) {
            $this->context['has_admin'] = true;

            return null;
        }

        $request = $this->container->get('request_stack')->getCurrentRequest();

        if (null === $request) {
            throw new \RuntimeException('The request stack did not contain a request');
        }

        if ('tl_admin' !== $request->request->get('FORM_SUBMIT')) {
            return null;
        }

        $username = $request->request->get('username');
        $name = $request->request->get('name');
        $email = $request->request->get('email');
        $password = $request->request->get('password');
        $confirmation = $request->request->get('confirmation');

        $this->context['admin_username_value'] = $username;
        $this->context['admin_name_value'] = $name;
        $this->context['admin_email_value'] = $email;

        // All fields are mandatory
        if ('' === $username || '' === $name || '' === $email || '' === $password) {
            $this->context['admin_error'] = $this->trans('admin_error');

            return null;
        }

        // Do not allow special characters in usernames
        if (preg_match('/[#()\/<=>]/', $username)) {
            $this->context['admin_username_error'] = $this->trans('admin_error_extnd');

            return null;
        }

        // The username must not contain whitespace characters (see #4006)
        if (false !== strpos($username, ' ')) {
            $this->context['admin_username_error'] = $this->trans('admin_error_no_space');

            return null;
        }

        // Validate the e-mail address (see #6003)
        if (filter_var($email, FILTER_VALIDATE_EMAIL) !== $email) {
            $this->context['admin_email_error'] = $this->trans('admin_error_email');

            return null;
        }

        // The passwords do not match
        if ($password !== $confirmation) {
            $this->context['admin_password_error'] = $this->trans('admin_error_password_match');

            return null;
        }

        $minlength = $installTool->getConfig('minPasswordLength');

        // The password is too short
        if (Utf8::strlen($password) < $minlength) {
            $this->context['admin_password_error'] = sprintf($this->trans('password_too_short'), $minlength);

            return null;
        }

        // Password and username are the same
        if ($password === $username) {
            $this->context['admin_password_error'] = sprintf($this->trans('admin_error_password_user'), $minlength);

            return null;
        }

        $installTool->persistConfig('adminEmail', $email);

        $installTool->persistAdminUser(
            $username,
            $name,
            $email,
            $password,
            $request->getLocale()
        );

        return $this->getRedirectResponse();
    }

    private function render(string $name, array $context = []): Response
    {
        return new Response(
            $this->container->get('twig')->render(
                '@ContaoInstallation/'.$name,
                $this->addDefaultsToContext($context)
            )
        );
    }

    private function trans(string $key): string
    {
        return $this->container->get('translator')->trans($key);
    }

    /**
     * Returns a redirect response to reload the page.
     *
     * @throws \RuntimeException
     */
    private function getRedirectResponse(): RedirectResponse
    {
        $request = $this->container->get('request_stack')->getCurrentRequest();

        if (null === $request) {
            throw new \RuntimeException('The request stack did not contain a request');
        }

        return new RedirectResponse($request->getRequestUri());
    }

    /**
     * Adds the default values to the context.
     *
     * @throws \RuntimeException
     *
     * @return array<string,string>
     */
    private function addDefaultsToContext(array $context): array
    {
        $context = array_merge($this->context, $context);

        if (!isset($context['request_token'])) {
            $context['request_token'] = $this->getRequestToken();
        }

        if (!isset($context['language'])) {
            $context['language'] = $this->container->get('translator')->getLocale();
        }

        if (!isset($context['ua'])) {
            $context['ua'] = $this->getUserAgentString();
        }

        if (!isset($context['path'])) {
            $request = $this->container->get('request_stack')->getCurrentRequest();

            if (null === $request) {
                throw new \RuntimeException('The request stack did not contain a request');
            }

            $context['path'] = $request->getBasePath();
        }

        return $context;
    }

    private function getRequestToken(): string
    {
        $tokenName = $this->getContainerParameter('contao.csrf_token_name');

        if (null === $tokenName) {
            return '';
        }

        return $this->container->get('contao.csrf.token_manager')->getToken($tokenName)->getValue();
    }

    /**
     * @return string|bool|null
     */
    private function getContainerParameter(string $name)
    {
        if ($this->container->hasParameter($name)) {
            return $this->container->getParameter($name);
        }

        return null;
    }

    private function getUserAgentString(): string
    {
        if (!$this->container->has('contao.framework') || !$this->container->get('contao.framework')->isInitialized()) {
            return '';
        }

        return Environment::get('agent')->class;
    }
}
