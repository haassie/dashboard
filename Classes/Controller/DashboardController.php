<?php
declare(strict_types=1);

namespace FriendsOfTYPO3\Dashboard\Controller;

use FriendsOfTYPO3\Dashboard\Registry\WidgetRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Extbase\Mvc\View\ViewInterface;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;

class DashboardController
{
    /**
     * @var ModuleTemplate
     */
    protected $moduleTemplate;

    /**
     * @var WidgetRegistry
     */
    protected $widgetRegistry;

    /**
     * @var UriBuilder
     */
    protected $uriBuilder;

    /** @var ViewInterface */
    protected $view;

    /**
     * @var array
     */
    protected $cssFiles = [];

    public function __construct()
    {
        $this->moduleTemplate = GeneralUtility::makeInstance(ModuleTemplate::class);
        $this->widgetRegistry = GeneralUtility::makeInstance(WidgetRegistry::class);
        $this->uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);

    }

    /**
     * Main entry method: Dispatch to other actions - those method names that end with "Action".
     *
     * @param ServerRequestInterface $request the current request
     * @return ResponseInterface the response with the content
     */
    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $publicResourcesPath = PathUtility::getAbsoluteWebPath(ExtensionManagementUtility::extPath('dashboard')) . 'Resources/Public/';

        $this->moduleTemplate->getPageRenderer()->addRequireJsConfiguration(
            array(
                'paths' => array(
                    'dashboard' => $publicResourcesPath . 'JavaScript',
                    'muuri' => $publicResourcesPath . 'JavaScript/Dist/Muuri',
                ),
            )
        );

        $this->moduleTemplate->getPageRenderer()->loadRequireJsModule('muuri');
        $this->moduleTemplate->getPageRenderer()->loadRequireJsModule('dashboard/Grid');
        $this->moduleTemplate->getPageRenderer()->loadRequireJsModule('dashboard/WidgetContentCollector');
        $this->moduleTemplate->getPageRenderer()->addCssFile($publicResourcesPath . 'CSS/Dashboard.css');

        $action = $request->getQueryParams()['action'] ?? $request->getParsedBody()['action'] ?? 'main';
        $this->initializeView($action);

        $result = call_user_func_array([$this, $action . 'Action'], [$request]);
        if ($result instanceof ResponseInterface) {
            return $result;
        }

        foreach ($this->cssFiles as $cssFile) {
            $this->moduleTemplate->getPageRenderer()->addCssFile($cssFile);
        }

        $this->moduleTemplate->setContent($this->view->render());
        return new HtmlResponse($this->moduleTemplate->renderContent());
    }

    public function mainAction(ServerRequestInterface $request): void
    {
        $widgets = $this->getWidgetsForCurrentUser();

        $this->view->assign('widgets', $widgets);
    }

    public function setActiveDashboardAction(ServerRequestInterface $request): ResponseInterface
    {
        //TODO: Save currentDashboard to user settings
        $this->getBackendUser()->pushModuleData('web_dashboard/current_dashboard/', $request->getQueryParams()['currentDashboard']);

        $route = $this->uriBuilder->buildUriFromRoute('dashboard', ['action' => 'main']);
        return new RedirectResponse($route);

    }

    /**
     * Sets up the Fluid View.
     *
     * @param string $templateName
     */
    protected function initializeView(string $templateName): void
    {
        $this->view = GeneralUtility::makeInstance(StandaloneView::class);
        $this->view->setTemplate($templateName);
        $this->view->setTemplateRootPaths(['EXT:dashboard/Resources/Private/Templates/Dashboard']);
        $this->view->setPartialRootPaths(['EXT:dashboard/Resources/Private/Partials']);
        $this->view->setLayoutRootPaths(['EXT:dashboard/Resources/Private/Layouts']);

        $this->addDashboardSelector();
    }

    protected function getWidgetsForCurrentUser(): array
    {
        $widgets = [];

        $tmpWidgets = $this->getBackendUser()->getModuleData('web_dashboard/dashboard/');
        if (!empty($tmpWidgets)) {
            foreach ($tmpWidgets as $tmpWidget) {
                $widgets[] = $this->prepareWidgetElement($tmpWidget['key'], $tmpWidget['config']);
            }
        } else {
            // TODO: default widgets when no user settings are found
            $widgets[] = $this->prepareWidgetElement('numberOfBackendUsers');
            $widgets[] = $this->prepareWidgetElement('numberOfAdminBackendUsers');
            $widgets[] = $this->prepareWidgetElement('lastLogins');
        }

        return $widgets;
    }

    public function prepareWidgetElement($widgetKey, $config = []): array
    {
        $widgetObject = $this->widgetRegistry->getWidgetObject($widgetKey);

        foreach($widgetObject->getCssFiles() as $cssFile) {
            if (!in_array($cssFile, $this->cssFiles, true)) {
                $this->cssFiles[] = $cssFile;
            }
        }

        return [
            'key' => $widgetKey,
            'height' => $widgetObject->getHeight(),
            'width' => $widgetObject->getWidth(),
            'title' => $widgetObject->getTitle(),
            'config' => $config
        ];
    }

    protected function addDashboardSelector(): void
    {
        $currentDashboard = $this->getBackendUser()->getModuleData('web_dashboard/current_dashboard/') ?: 'default';

        $availableDashboards = [
            'default' => [
                'label' => 'Default dashboard'
            ],
            'test' => [
                'label' => 'Test dashboard'
            ],
        ];

        $dashboardSelector = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $dashboardSelector->setIdentifier('currentDashboard');
        $dashboardSelector->setLabel('test');

        foreach ($availableDashboards as $dashboardKey => $dashboardConfig) {
            $parameters = [
                'currentDashboard' => $dashboardKey,
                'action' => 'setActiveDashboard'
            ];
            $url = (string)$this->uriBuilder->buildUriFromRoute('dashboard', $parameters);
            $menuItem = $dashboardSelector
                ->makeMenuItem()
                ->setTitle(
                    $this->getLanguageService()->sL($dashboardConfig['label']) ?: $dashboardKey
                )
                ->setHref($url);
            if ($currentDashboard === $dashboardKey) {
                $menuItem->setActive(true);
            }
            $dashboardSelector->addMenuItem($menuItem);
        }
        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($dashboardSelector);
    }

    /**
     * @return BackendUserAuthentication
     */
    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    /**
     * @return LanguageService
     */
    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

}
