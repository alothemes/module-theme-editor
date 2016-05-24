<?php
namespace Swissup\ThemeEditor\Model;

use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Css
{
    /** Themes */
    const ABSOLUTE_THEME  = 'swissup_absolute';
    const ARGENTO_ESSENCE = 'swissup_argento_essence';

    /** Modes */
    const MODE_CREATE_AND_SAVE = 'create_save';
    const MODE_UPDATE          = 'update';

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;
    /**
     * @var \Magento\MediaStorage\Model\File\Storage\File
     */
    protected $mediaStorage = null;
    /**
     * Config data loader
     *
     * @var \Magento\Config\Model\Config\Loader
     */
    protected $configLoader;
    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;
    /**
     * @var \Swissup\ThemeEditor\Helper\Helper
     */
    protected $helper;

    /**
     * @param \Magento\Framework\Message\ManagerInterface $messageManager
     * @param \Magento\MediaStorage\Model\File\Storage\FileFactory $mediaStorageFactory
     * @param \Magento\Config\Model\Config\Loader $configLoader
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Swissup\ThemeEditor\Helper\Helper $helper
     */
    public function __construct(
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Magento\MediaStorage\Model\File\Storage\FileFactory $mediaStorageFactory,
        \Magento\Config\Model\Config\Loader $configLoader,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Swissup\ThemeEditor\Helper\Helper $helper
    ) {
        $this->messageManager = $messageManager;
        $this->mediaStorage = $mediaStorageFactory->create();
        $this->configLoader = $configLoader;
        $this->storeManager = $storeManager;
        $this->helper = $helper;
    }

    /**
     * Get themes list
     *
     * @return array
     */
    public function getThemesList()
    {
        return [
            self::ABSOLUTE_THEME,
            self::ARGENTO_ESSENCE
        ];
    }

    public function getStorage()
    {
        return $this->mediaStorage;
    }

    /**
     * @param string $theme
     * @param string $storeId
     * @param string $websiteId
     * @param string $mode
     * @return void
     */
    public function generateAndSave($theme, $storeId, $websiteId, $mode)
    {
        list($storeCode, $websiteCode) = $this->getCodesFromIds($storeId, $websiteId);
        $filePath = $this->getFilePath($theme, $storeCode, $websiteCode);
        if (self::MODE_UPDATE === $mode) {
            if (!file_exists($this->getStorage()->getMediaBaseDirectory() . '/' . $filePath)) {
                return;
            }
        }
        $config = $this->getThemeConfig($theme, $storeId, $websiteId);
        $css    = $this->convertConfigToCss($theme, $config);
        try {
            $this->getStorage()->saveFile([
                'content'  => $css,
                'filename' => $filePath
            ], true);
        } catch (\Exception $e) {
            $this->messageManager->addError($e->getMessage());
        }
    }

    /**
     * @param string $theme
     * @param string $storeId
     * @param string $websiteId
     * @return void
     */
    public function removeFile($theme, $storeId, $websiteId)
    {
        list($storeCode, $websiteCode) = $this->getCodesFromIds($storeId, $websiteId);
        $filePath = $this->getFilePath($theme, $storeCode, $websiteCode);
        @unlink($this->getStorage()->getMediaBaseDirectory() . '/' . $filePath);
    }

    /**
     * Retrieve css filepath, relative to media folder
     *
     * @param string $theme
     * @param string $storeCode
     * @param string $websiteCode
     * @return string
     */
    public function getFilePath($theme, $storeCode, $websiteCode)
    {
        $suffix = '_backend.css';
        if ($storeCode) {
            $prefix = implode('_', [
                $websiteCode,
                $storeCode
            ]);
        } elseif ($websiteCode) {
            $prefix = $websiteCode;
        } else {
            $prefix = 'admin';
        }
        return str_replace('_', '/', $theme) . '/' . 'css' . '/' . $prefix . $suffix;
    }

    /**
     * @param string $theme
     * @param string $storeId
     * @param string $websiteId
     * @return array
     */
    public function getThemeConfig($theme, $storeId, $websiteId)
    {
        if ($storeId) {
            $scope     = ScopeInterface::SCOPE_STORES;
            $scopeCode = $storeId;
        } elseif ($websiteId) {
            $scope     = ScopeInterface::SCOPE_WEBSITES;
            $scopeCode = $websiteId;
        } else {
            $scope     = ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
            $scopeCode = null;
        }
        $node = $this->configLoader->getConfigByPath(
            $theme,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            null
        );
        if (!$node) {
            return [];
        }

        $config = [];
        foreach ($node as $group => $values) {
            $value = $this->helper->getScopeConfig()->getValue($group, $scope, $scopeCode);
            $groupId = explode('/', $group)[1];
            $valueId = $this->helper->camel2dashed(explode('/', $values['path'])[2]);
            $config[$groupId][$valueId] = $value;
        }

        return $config;
    }
    /**
     * @param String $theme
     * @param array $config
     * <pre>
     *  background
     *      body_background-color => #fff
     *      ...
     *  font
     *      body_font-family      => Helvetica,Arial,sans-serif
     *      page-header_color     => #000
     *      page-header_font-size => 12px
     *      ...
     *  style
     *      css => inline css
     *  css_selector
     *      body => body
     *      page-header => h1
     * </pre>
     */
    public function convertConfigToCss($theme, $config)
    {
        $groupedCss   = [];
        $groupsToSkip = ['css_selector', 'head'];
        $propsToSkip  = ['heading', 'head_link', 'sticky_header'];
        foreach ($config as $groupName => $groupValues) {
            if (in_array($groupName, $groupsToSkip)) {
                continue;
            }
            foreach ($groupValues as $name => $value) {
                $value = (string)$value;
                list($key, $prop) = explode('_', $name);
                if (in_array($prop, $propsToSkip)) {
                    continue;
                }
                if ($method = $this->_getExtractorMethodName($prop)) {
                    $value = $this->$method($value);
                }
                if (false === $value || strlen($value) === 0) {
                    continue; // feature to keep default theme styles from theme.css
                }
                $groupedCss[$key][] = "{$prop}:{$value};";
            }
        }
        $css = '';
        foreach ($groupedCss as $key => $cssArray) {
            if (empty($config['css_selector'])
                || !is_array($config['css_selector'])
                || empty($config['css_selector'][$key])) {
                $selector = $this->helper->getScopeConfig()
                    ->getValue($theme . '/css_selector/' .$key);
                if (empty($selector)) {
                    continue;
                }
            } else {
                $selector = $config['css_selector'][$key];
            }
            $styles   = implode('', $cssArray);
            $css .= "{$selector}{{$styles}}\n";
        }
        if (!empty($config['head']['css'])) {
            $css .= $config['head']['css'];
        }
        return $css;
    }
    /**
     * @param string $property
     * @return string|false
     */
    protected function _getExtractorMethodName($property)
    {
        $property = str_replace('-', ' ', $property);
        $property = ucwords($property);
        $property = str_replace(' ', '', $property);
        $method = '_extract' . $property;
        if (method_exists($this, $method)) {
            return $method;
        }
        return false;
    }
    protected function _extractBackgroundImage($value)
    {
        // fix to prevent activating of 'Use default' checkbox, when image is deleted
        if (empty($value) || 'none' === $value) {
            $value = 'none';
        } else {
            $value = 'url(../images/' . $value . ')';
        }
        return $value;
    }
    protected function _extractBackgroundColor($value)
    {
        if (empty($value)) {
            $value = 'transparent';
        }
        return $value;
    }
    protected function _extractBackgroundPosition($value)
    {
        return str_replace(',', ' ', $value);
    }

    /**
     * Get website and store codes from ids
     * @param  int $storeId
     * @param  int $websiteId
     * @return Array
     */
    private function getCodesFromIds($storeId, $websiteId)
    {
        $storeCode = null;
        $websiteCode = null;
        if ($storeId) {
            $storeCode = $this->storeManager->getStore($storeId)->getCode();
            $websiteCode = $this->storeManager->getStore($storeId)->getWebsite()->getCode();
        } else if ($websiteId) {
            $websiteCode = $this->storeManager->getWebsite($websiteId)->getCode();
        }

        return [$storeCode, $websiteCode];
    }
}