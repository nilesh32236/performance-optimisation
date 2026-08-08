"use strict";
(globalThis["webpackChunkperformance_optimisation"] ||= []).push([["tab-file-optimization"],{

/***/ "./src/components/CriticalCssPanel.js"
/*!********************************************!*\
  !*** ./src/components/CriticalCssPanel.js ***!
  \********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @fortawesome/free-solid-svg-icons */ "./node_modules/@fortawesome/free-solid-svg-icons/index.mjs");
/* harmony import */ var _fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! @fortawesome/react-fontawesome */ "./node_modules/@fortawesome/react-fontawesome/index.es.js");
/* harmony import */ var _common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ./common/LoadingSubmitButton */ "./src/components/common/LoadingSubmitButton.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__);






const STATUS_CONFIG = {
  ready: {
    icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faCheckCircle,
    className: 'wppo-badge--success',
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Generated', 'performance-optimisation')
  },
  pending: {
    icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faClock,
    className: 'wppo-badge--info',
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Pending', 'performance-optimisation')
  },
  failed: {
    icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faTimesCircle,
    className: 'wppo-badge--error',
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Failed', 'performance-optimisation')
  },
  none: {
    icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faExclamationTriangle,
    className: 'wppo-badge--warning',
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Not Generated', 'performance-optimisation')
  }
};
const CriticalCssPanel = ({
  status = {},
  onRegenerate
}) => {
  const [isRegenerating, setIsRegenerating] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(false);
  const handleRegenerate = async () => {
    setIsRegenerating(true);
    try {
      await onRegenerate();
    } catch (err) {
      console.error('Failed to regenerate CCSS', err);
    } finally {
      setIsRegenerating(false);
    }
  };
  const entries = Object.entries(status);
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("div", {
    className: "wppo-ccss-panel wppo-mt-20",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("div", {
      className: "wppo-field-label",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Critical CSS Status', 'performance-optimisation')
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("p", {
      className: "wppo-text-muted wppo-mb-12",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Critical CSS is generated per template. Regenerate after theme changes.', 'performance-optimisation')
    }), entries.length > 0 ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("div", {
      className: "wppo-ccss-status-list wppo-mb-16",
      children: entries.map(([hash, entry]) => {
        const statusKey = typeof entry === 'string' ? entry : entry.status;
        const label = typeof entry === 'object' && entry.label ? entry.label : hash.substring(0, 8) + '…';
        const config = STATUS_CONFIG[statusKey] || STATUS_CONFIG.none;
        return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("div", {
          className: "wppo-ccss-status-item",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("span", {
            className: "wppo-ccss-status-hash",
            children: label
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("span", {
            className: `wppo-badge ${config.className}`,
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_3__.FontAwesomeIcon, {
              icon: config.icon
            }), config.label]
          })]
        }, hash);
      })
    }) : /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("div", {
      className: "wppo-text-muted wppo-mb-16",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('No templates found. Save settings and regenerate.', 'performance-optimisation')
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_4__["default"], {
      className: "wppo-button wppo-button--secondary",
      isLoading: isRegenerating,
      onClick: handleRegenerate,
      label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Regenerate All', 'performance-optimisation')
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (CriticalCssPanel);

/***/ },

/***/ "./src/components/FileOptimization.js"
/*!********************************************!*\
  !*** ./src/components/FileOptimization.js ***!
  \********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _lib_util__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../lib/util */ "./src/lib/util.js");
/* harmony import */ var _lib_apiRequest__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../lib/apiRequest */ "./src/lib/apiRequest.js");
/* harmony import */ var _lib_useNotice__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ../lib/useNotice */ "./src/lib/useNotice.js");
/* harmony import */ var _fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! @fortawesome/react-fontawesome */ "./node_modules/@fortawesome/react-fontawesome/index.es.js");
/* harmony import */ var _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! @fortawesome/free-solid-svg-icons */ "./node_modules/@fortawesome/free-solid-svg-icons/index.mjs");
/* harmony import */ var _common_Tooltip__WEBPACK_IMPORTED_MODULE_7__ = __webpack_require__(/*! ./common/Tooltip */ "./src/components/common/Tooltip.js");
/* harmony import */ var _common_FeatureHeader__WEBPACK_IMPORTED_MODULE_8__ = __webpack_require__(/*! ./common/FeatureHeader */ "./src/components/common/FeatureHeader.js");
/* harmony import */ var _common_FeatureCard__WEBPACK_IMPORTED_MODULE_9__ = __webpack_require__(/*! ./common/FeatureCard */ "./src/components/common/FeatureCard.js");
/* harmony import */ var _common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_10__ = __webpack_require__(/*! ./common/LoadingSubmitButton */ "./src/components/common/LoadingSubmitButton.js");
/* harmony import */ var _common_SwitchField__WEBPACK_IMPORTED_MODULE_11__ = __webpack_require__(/*! ./common/SwitchField */ "./src/components/common/SwitchField.js");
/* harmony import */ var _common_NoticeBanner__WEBPACK_IMPORTED_MODULE_12__ = __webpack_require__(/*! ./common/NoticeBanner */ "./src/components/common/NoticeBanner.js");
/* harmony import */ var _CriticalCssPanel__WEBPACK_IMPORTED_MODULE_13__ = __webpack_require__(/*! ./CriticalCssPanel */ "./src/components/CriticalCssPanel.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__);















const FileOptimization = ({
  options = {},
  serverRules = null,
  serverRulesError = false,
  ccssStatus = {},
  onRetryServerRules,
  onCcssRefresh
}) => {
  const [activeSubTab, setActiveSubTab] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)('assets');
  const tabRefs = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useRef)({});
  const defaultSettings = {
    minifyJS: false,
    excludeJS: '',
    minifyCSS: false,
    excludeCSS: '',
    combineCSS: false,
    excludeCombineCSS: '',
    minifyHTML: false,
    deferJS: false,
    excludeDeferJS: '',
    delayJS: false,
    excludeDelayJS: '',
    delayJSDefaultStrategy: options.delayJSDefaultStrategy || 'interaction',
    delayJSIdleList: options.delayJSIdleList || '',
    delayJSViewportList: options.delayJSViewportList || '',
    delayJSPriority: options.delayJSPriority || '',
    delayJSIdleTimeout: options.delayJSIdleTimeout || 3000,
    removeWooCSSJS: false,
    excludeUrlToKeepJSCSS: '',
    removeCssJsHandle: '',
    enableServerRules: false,
    criticalCSS: false,
    hostGoogleFontsLocally: false,
    cdnURL: '',
    removeUnusedCSS: false,
    excludeUnusedCSS: '',
    disableEmojis: false,
    disableEmbeds: false,
    disableDashicons: false,
    disableXMLRPC: false,
    // Mirrors the pre-6.9 PHP default. PHP always emits the key on WP 6.9+ (where
    // core loads block assets on demand by default), so this fallback is only used
    // on older cores and never contradicts the backend default.
    blockAssetsOnDemand: false,
    loadAllCoreBlockAssets: false,
    heartbeatControl: 'default',
    minifyInlineCSS: false,
    minifyInlineJS: false,
    removeHTMLComments: true,
    ...options
  };
  const [settings, setSettings] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(defaultSettings);
  const [isLoading, setIsLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(false);
  const {
    notice,
    notify,
    dismiss
  } = (0,_lib_useNotice__WEBPACK_IMPORTED_MODULE_4__["default"])();
  const withNotification = async (apiCallPromise, successMessage, errorMessage) => {
    setIsLoading(true);
    dismiss();
    try {
      const res = await apiCallPromise;
      if (res.success) {
        notify({
          type: 'success',
          message: res.message || successMessage,
          durationMs: 3000
        });
      } else {
        notify({
          type: 'error',
          message: res.message || errorMessage,
          durationMs: 3000
        });
      }
    } catch (err) {
      console.error(errorMessage, err);
      notify({
        type: 'error',
        message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('An unexpected error occurred.', 'performance-optimisation'),
        durationMs: 3000
      });
    } finally {
      setIsLoading(false);
    }
  };
  const handleRegenerateCss = async () => {
    await withNotification((async () => {
      const res = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_3__.apiCall)('regenerate_ccss');
      if (res?.success && onCcssRefresh) {
        onCcssRefresh();
      }
      return res;
    })(), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Critical CSS regeneration queued.', 'performance-optimisation'), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Failed to regenerate critical CSS.', 'performance-optimisation'));
  };
  const handleRegenerateUsedCSS = async () => {
    await withNotification((async () => {
      const saveRes = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_3__.apiCall)('update_settings', {
        tab: 'file_optimisation',
        settings: {
          ...settings,
          delayJSDefaultStrategy: settings.delayJSDefaultStrategy,
          delayJSIdleList: settings.delayJSIdleList,
          delayJSViewportList: settings.delayJSViewportList,
          delayJSPriority: settings.delayJSPriority,
          delayJSIdleTimeout: settings.delayJSIdleTimeout
        }
      });
      if (!saveRes.success) {
        return saveRes;
      }
      return await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_3__.apiCall)('used_css_regenerate');
    })(), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Used CSS regeneration queued.', 'performance-optimisation'), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Failed to regenerate used CSS.', 'performance-optimisation'));
  };
  const handleSubmit = async e => {
    if (e) {
      e.preventDefault();
    }
    await withNotification((0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_3__.apiCall)('update_settings', {
      tab: 'file_optimisation',
      settings: {
        ...settings,
        delayJSDefaultStrategy: settings.delayJSDefaultStrategy,
        delayJSIdleList: settings.delayJSIdleList,
        delayJSViewportList: settings.delayJSViewportList,
        delayJSPriority: settings.delayJSPriority,
        delayJSIdleTimeout: settings.delayJSIdleTimeout
      }
    }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Settings updated successfully.', 'performance-optimisation'), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Failed to update settings.', 'performance-optimisation'));
  };
  const subTabs = [{
    id: 'assets',
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Assets', 'performance-optimisation'),
    icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_6__.faCode
  }, {
    id: 'scripts',
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Scripts', 'performance-optimisation'),
    icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_6__.faRocket
  }, {
    id: 'ecommerce',
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('E-Commerce', 'performance-optimisation'),
    icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_6__.faStore
  }, {
    id: 'network',
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Network', 'performance-optimisation'),
    icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_6__.faServer
  }, {
    id: 'core',
    label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Core', 'performance-optimisation'),
    icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_6__.faShieldAlt
  }];
  const handleSubTabKeyDown = (e, index) => {
    let nextIndex;
    if (e.key === 'ArrowRight') {
      nextIndex = (index + 1) % subTabs.length;
    } else if (e.key === 'ArrowLeft') {
      nextIndex = (index - 1 + subTabs.length) % subTabs.length;
    } else {
      return;
    }
    e.preventDefault();
    const nextTab = subTabs[nextIndex];
    setActiveSubTab(nextTab.id);

    // Move focus to the next button.
    const nextButton = tabRefs.current[nextTab.id];
    if (nextButton) {
      nextButton.focus();
    }
  };
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
    className: "wppo-dashboard-view",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)(_common_FeatureHeader__WEBPACK_IMPORTED_MODULE_8__["default"], {
      title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('File Optimisation', 'performance-optimisation'),
      description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Fine-tune how your site delivers CSS, JS, and HTML for maximum performance.', 'performance-optimisation'),
      actions: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_10__["default"], {
        className: "wppo-button wppo-button--primary",
        isLoading: isLoading,
        onClick: handleSubmit,
        label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Save Settings', 'performance-optimisation')
      }),
      children: [notice && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_NoticeBanner__WEBPACK_IMPORTED_MODULE_12__["default"], {
        type: notice.type,
        message: notice.message,
        className: "wppo-mb-20"
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("div", {
        className: "wppo-sub-tabs",
        role: "tablist",
        children: subTabs.map((tab, index) => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("button", {
          id: `tab-${tab.id}`,
          ref: el => tabRefs.current[tab.id] = el,
          className: `wppo-sub-tab${activeSubTab === tab.id ? ' wppo-sub-tab--active' : ''}`,
          onClick: () => setActiveSubTab(tab.id),
          onKeyDown: e => handleSubTabKeyDown(e, index),
          type: "button",
          role: "tab",
          tabIndex: activeSubTab === tab.id ? 0 : -1,
          "aria-selected": activeSubTab === tab.id,
          "aria-controls": `panel-${tab.id}`,
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_5__.FontAwesomeIcon, {
            icon: tab.icon
          }), tab.label]
        }, tab.id))
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
      className: "wppo-tab-content",
      children: [activeSubTab === 'assets' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
        id: "panel-assets",
        className: "wppo-stacked-cards",
        role: "tabpanel",
        "aria-labelledby": "tab-assets",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_9__["default"], {
          title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('CSS Optimisation', 'performance-optimisation'),
          icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_5__.FontAwesomeIcon, {
            icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_6__.faCode
          }),
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
            className: "wppo-field-group",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_11__["default"], {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Minify CSS', 'performance-optimisation'),
              description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Remove whitespace and comments from stylesheets to reduce file size.', 'performance-optimisation'),
              name: "minifyCSS",
              checked: settings.minifyCSS,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_11__["default"], {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Combine CSS', 'performance-optimisation'),
              description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Merge all CSS files into a single file to reduce the number of HTTP requests.', 'performance-optimisation'),
              name: "combineCSS",
              checked: settings.combineCSS,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
            }), settings.combineCSS && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
              className: "wppo-field",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "excludeCombineCSS",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Exclude CSS from Combining', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("textarea", {
                className: "wppo-textarea",
                id: "excludeCombineCSS",
                name: "excludeCombineCSS",
                rows: "3",
                placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Handles or partial URLs', 'performance-optimisation'),
                value: settings.excludeCombineCSS,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
              })]
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_Tooltip__WEBPACK_IMPORTED_MODULE_7__["default"], {
              content: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Removes CSS rules not used on the current page, similar to PurgeCSS. Reduces page weight significantly.', 'performance-optimisation'),
              children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_11__["default"], {
                label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Remove Unused CSS', 'performance-optimisation'),
                description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Scan pages and remove CSS rules that are not used. Reduces file size by 30–80% and helps pass PageSpeed audits.', 'performance-optimisation'),
                name: "removeUnusedCSS",
                checked: settings.removeUnusedCSS,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
              })
            }), settings.removeUnusedCSS && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
              className: "wppo-field",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "excludeUnusedCSS",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Safelist Selectors', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("textarea", {
                className: "wppo-textarea",
                id: "excludeUnusedCSS",
                name: "excludeUnusedCSS",
                rows: "4",
                placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Selectors to always keep (one per line, e.g. .my-dynamic-class)', 'performance-optimisation'),
                value: settings.excludeUnusedCSS,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("button", {
                className: "wppo-button wppo-button--secondary wppo-mt-12",
                onClick: handleRegenerateUsedCSS,
                type: "button",
                disabled: isLoading,
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Regenerate Used CSS', 'performance-optimisation')
              })]
            }), settings.minifyCSS && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
              className: "wppo-field",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "excludeCSS",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Exclude CSS from Minification', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("textarea", {
                className: "wppo-textarea",
                id: "excludeCSS",
                name: "excludeCSS",
                rows: "3",
                placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Handles or partial URLs (one per line)', 'performance-optimisation'),
                value: settings.excludeCSS,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
              })]
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_11__["default"], {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Critical CSS', 'performance-optimisation'),
              description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Generate and inline above-the-fold CSS, then defer full stylesheets. Improves FCP and LCP by eliminating render-blocking CSS.', 'performance-optimisation'),
              name: "criticalCSS",
              checked: settings.criticalCSS,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
            }), settings.criticalCSS && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_CriticalCssPanel__WEBPACK_IMPORTED_MODULE_13__["default"], {
              status: ccssStatus,
              onRegenerate: handleRegenerateCss
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_11__["default"], {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Host Google Fonts Locally', 'performance-optimisation'),
              description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Automatically detect Google Fonts and serve them from your own server. Eliminates external DNS lookups, improves GDPR compliance, and applies font-display: swap.', 'performance-optimisation'),
              name: "hostGoogleFontsLocally",
              checked: settings.hostGoogleFontsLocally,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
            })]
          })
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_9__["default"], {
          title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('HTML Optimisation', 'performance-optimisation'),
          icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_5__.FontAwesomeIcon, {
            icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_6__.faCode
          }),
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_11__["default"], {
            label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Minify HTML', 'performance-optimisation'),
            description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Compress the HTML output of your website by removing unnecessary whitespace and comments.', 'performance-optimisation'),
            name: "minifyHTML",
            checked: settings.minifyHTML,
            onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_11__["default"], {
            label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Remove HTML Comments', 'performance-optimisation'),
            description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Strip HTML comments from the output (except IE conditional comments).', 'performance-optimisation'),
            name: "removeHTMLComments",
            checked: settings.removeHTMLComments,
            onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_11__["default"], {
            label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Minify Inline CSS', 'performance-optimisation'),
            description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Minify CSS within <style> tags using the PHP minifier.', 'performance-optimisation'),
            name: "minifyInlineCSS",
            checked: settings.minifyInlineCSS,
            onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_11__["default"], {
            label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Minify Inline JavaScript', 'performance-optimisation'),
            description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Minify JavaScript within <script> tags using the PHP minifier.', 'performance-optimisation'),
            name: "minifyInlineJS",
            checked: settings.minifyInlineJS,
            onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
          })]
        })]
      }), activeSubTab === 'scripts' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
        id: "panel-scripts",
        className: "wppo-stacked-cards",
        role: "tabpanel",
        "aria-labelledby": "tab-scripts",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_9__["default"], {
          title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('JavaScript Loading', 'performance-optimisation'),
          icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_5__.FontAwesomeIcon, {
            icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_6__.faRocket
          }),
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
            className: "wppo-field-group",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_11__["default"], {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Minify JavaScript', 'performance-optimisation'),
              description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Compress JS files by removing whitespace and comments to reduce execution time.', 'performance-optimisation'),
              name: "minifyJS",
              checked: settings.minifyJS,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_11__["default"], {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Defer JavaScript', 'performance-optimisation'),
              description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Load scripts after the page renders to prevent render-blocking and improve page speed.', 'performance-optimisation'),
              name: "deferJS",
              checked: settings.deferJS,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
            }), settings.deferJS && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
              className: "wppo-field",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "excludeDeferJS",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Exclude JS from Deferring', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("textarea", {
                className: "wppo-textarea",
                id: "excludeDeferJS",
                name: "excludeDeferJS",
                rows: "3",
                placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Handles or partial URLs', 'performance-optimisation'),
                value: settings.excludeDeferJS,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
              })]
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_11__["default"], {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Delay JavaScript Execution', 'performance-optimisation'),
              description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Delay all scripts until the user interacts (keyboard/mouse) or load during idle/viewport. Reduces initial CPU usage but may break immediate functionality — test carefully.', 'performance-optimisation'),
              name: "delayJS",
              checked: settings.delayJS,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
            })]
          })
        }), (settings.minifyJS || settings.delayJS) && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_9__["default"], {
          title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Script Rules', 'performance-optimisation'),
          icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_5__.FontAwesomeIcon, {
            icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_6__.faRocket
          }),
          children: [settings.minifyJS && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
            className: "wppo-field",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("label", {
              className: "wppo-field-label",
              htmlFor: "excludeJS",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Exclude JS from Minification', 'performance-optimisation')
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("textarea", {
              className: "wppo-textarea",
              id: "excludeJS",
              name: "excludeJS",
              rows: "3",
              placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Handles or partial URLs', 'performance-optimisation'),
              value: settings.excludeJS,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
            })]
          }), settings.delayJS && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.Fragment, {
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
              className: "wppo-field wppo-mt-20",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "excludeDelayJS",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Scripts to Delay', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("textarea", {
                className: "wppo-textarea",
                id: "excludeDelayJS",
                name: "excludeDelayJS",
                rows: "3",
                placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Partial URLs or keywords', 'performance-optimisation'),
                value: settings.excludeDelayJS,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
              })]
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
              className: "wppo-field wppo-mt-16",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "delayJSDefaultStrategy",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Default Load Strategy', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("select", {
                className: "wppo-select",
                id: "delayJSDefaultStrategy",
                name: "delayJSDefaultStrategy",
                value: settings.delayJSDefaultStrategy,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings),
                children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("option", {
                  value: "interaction",
                  children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Interaction (load on user interaction)', 'performance-optimisation')
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("option", {
                  value: "idle",
                  children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Idle (load during browser idle)', 'performance-optimisation')
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("option", {
                  value: "viewport",
                  children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Viewport (load when near viewport)', 'performance-optimisation')
                })]
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("p", {
                className: "wppo-text-muted wppo-mt-8 wppo-text-small",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Default strategy for delayed scripts that are not in a specific list below.', 'performance-optimisation')
              })]
            }), (settings.delayJSDefaultStrategy === 'idle' || settings.delayJSIdleList) && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
              className: "wppo-field wppo-mt-16",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "delayJSIdleTimeout",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Idle Timeout (ms)', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("input", {
                className: "wppo-input",
                type: "number",
                id: "delayJSIdleTimeout",
                name: "delayJSIdleTimeout",
                min: "500",
                max: "30000",
                step: "100",
                value: settings.delayJSIdleTimeout,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("p", {
                className: "wppo-text-muted wppo-mt-8 wppo-text-small",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Maximum time (ms) to wait before loading idle scripts (default: 3000).', 'performance-optimisation')
              })]
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
              className: "wppo-field wppo-mt-16",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "delayJSIdleList",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Scripts to Load When Idle', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("textarea", {
                className: "wppo-textarea",
                id: "delayJSIdleList",
                name: "delayJSIdleList",
                rows: "3",
                placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Handles or partial URLs (one per line)', 'performance-optimisation'),
                value: settings.delayJSIdleList,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("p", {
                className: "wppo-text-muted wppo-mt-8 wppo-text-small",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('These scripts load via requestIdleCallback during browser idle time.', 'performance-optimisation')
              })]
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
              className: "wppo-field wppo-mt-16",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "delayJSViewportList",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Scripts to Load in Viewport', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("textarea", {
                className: "wppo-textarea",
                id: "delayJSViewportList",
                name: "delayJSViewportList",
                rows: "3",
                placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Handles or partial URLs (one per line)', 'performance-optimisation'),
                value: settings.delayJSViewportList,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("p", {
                className: "wppo-text-muted wppo-mt-8 wppo-text-small",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('These scripts load when their position enters the viewport.', 'performance-optimisation')
              })]
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
              className: "wppo-field wppo-mt-16",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "delayJSPriority",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Script Priority', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("textarea", {
                className: "wppo-textarea",
                id: "delayJSPriority",
                name: "delayJSPriority",
                rows: "3",
                placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('handle:high, other:low (one per line)', 'performance-optimisation'),
                value: settings.delayJSPriority,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("p", {
                className: "wppo-text-muted wppo-mt-8 wppo-text-small",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Syntax: handle:priority (high, normal, low). High-priority scripts load first.', 'performance-optimisation')
              })]
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
              className: "wppo-notice wppo-notice--warning wppo-mt-16",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_5__.FontAwesomeIcon, {
                icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_6__.faExclamationTriangle
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("span", {
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Delaying scripts can break immediate functionality. Test carefully.', 'performance-optimisation')
              })]
            })]
          })]
        })]
      }), activeSubTab === 'ecommerce' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("div", {
        id: "panel-ecommerce",
        className: "wppo-stacked-cards",
        role: "tabpanel",
        "aria-labelledby": "tab-ecommerce",
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_9__["default"], {
          title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('WooCommerce Core', 'performance-optimisation'),
          icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_5__.FontAwesomeIcon, {
            icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_6__.faStore
          }),
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
            className: "wppo-field-group",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_11__["default"], {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Optimize WooCommerce Assets', 'performance-optimisation'),
              description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Disable WooCommerce scripts and styles on non-ecommerce pages (e.g. blog, about). This reduces page weight but may break cart widgets on custom pages — verify your checkout flow after enabling.', 'performance-optimisation'),
              name: "removeWooCSSJS",
              checked: settings.removeWooCSSJS,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
            }), settings.removeWooCSSJS && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.Fragment, {
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
                className: "wppo-notice wppo-notice--warning",
                children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_5__.FontAwesomeIcon, {
                  icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_6__.faExclamationTriangle
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("span", {
                  children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('This may break carts on custom pages. Verify your checkout flow.', 'performance-optimisation')
                })]
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
                className: "wppo-stacked-cards wppo-mt-24",
                children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
                  className: "wppo-field",
                  children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("label", {
                    className: "wppo-field-label",
                    htmlFor: "excludeUrlToKeepJSCSS",
                    children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Keep Assets on these URLs', 'performance-optimisation')
                  }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("textarea", {
                    className: "wppo-textarea",
                    id: "excludeUrlToKeepJSCSS",
                    name: "excludeUrlToKeepJSCSS",
                    rows: "4",
                    placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('e.g. shop/.* (regex supported)', 'performance-optimisation'),
                    value: settings.excludeUrlToKeepJSCSS,
                    onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
                  })]
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
                  className: "wppo-field",
                  children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("label", {
                    className: "wppo-field-label",
                    htmlFor: "removeCssJsHandle",
                    children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Remove specific CSS/JS handles', 'performance-optimisation')
                  }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("textarea", {
                    className: "wppo-textarea",
                    id: "removeCssJsHandle",
                    name: "removeCssJsHandle",
                    rows: "4",
                    placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Handles (one per line)', 'performance-optimisation'),
                    value: settings.removeCssJsHandle,
                    onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
                  })]
                })]
              })]
            })]
          })
        })
      }), activeSubTab === 'network' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
        id: "panel-network",
        className: "wppo-stacked-cards",
        role: "tabpanel",
        "aria-labelledby": "tab-network",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_9__["default"], {
          title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Server Rules', 'performance-optimisation'),
          icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_5__.FontAwesomeIcon, {
            icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_6__.faServer
          }),
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
            className: "wppo-field-group",
            children: [serverRulesError ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
              className: "wppo-notice wppo-notice--error",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("span", {
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Unable to load server configuration. Check your server setup.', 'performance-optimisation')
              }), onRetryServerRules && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("button", {
                className: "wppo-button wppo-button--secondary",
                onClick: onRetryServerRules,
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Retry', 'performance-optimisation')
              })]
            }) : null, serverRules === null && !serverRulesError ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
              className: "wppo-loading-placeholder",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_5__.FontAwesomeIcon, {
                icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_6__.faSpinner,
                spin: true
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("span", {
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Loading server configuration…', 'performance-optimisation')
              })]
            }) : null, serverRules !== null && !serverRulesError ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.Fragment, {
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_Tooltip__WEBPACK_IMPORTED_MODULE_7__["default"], {
                content: serverRules?.server_type !== 'apache' ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Server rules require Apache.', 'performance-optimisation') : '',
                children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_11__["default"], {
                  label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Enable Server Rules (.htaccess)', 'performance-optimisation'),
                  description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Write performance rules (browser caching, GZIP compression, etc.) directly to your .htaccess file for server-level optimisation. Requires Apache. Ensure you have FTP access for recovery if something goes wrong.', 'performance-optimisation'),
                  name: "enableServerRules",
                  checked: serverRules?.server_type === 'apache' && settings.enableServerRules,
                  disabled: serverRules?.server_type !== 'apache',
                  onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
                })
              }), serverRules?.server_type === 'apache' && settings.enableServerRules && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
                className: "wppo-notice wppo-notice--warning",
                children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_5__.FontAwesomeIcon, {
                  icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_6__.faExclamationTriangle
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("span", {
                  children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('This modifies your .htaccess. Ensure you have FTP access for recovery.', 'performance-optimisation')
                })]
              }), serverRules?.server_type === 'nginx' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
                className: "wppo-nginx-rules wppo-mt-20",
                children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
                  className: "wppo-notice wppo-notice--info wppo-mb-16",
                  children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_5__.FontAwesomeIcon, {
                    icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_6__.faServer
                  }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("span", {
                    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("strong", {
                      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Nginx Detected:', 'performance-optimisation')
                    }), ' ', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Server rules cannot be applied automatically on Nginx. Please copy the rules below into your server configuration.', 'performance-optimisation')]
                  })]
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("div", {
                  className: "wppo-field-label",
                  children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Nginx Configuration', 'performance-optimisation')
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("pre", {
                  className: "wppo-code-block",
                  children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("code", {
                    children: serverRules.nginx
                  })
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("p", {
                  className: "wppo-text-muted wppo-mt-12 wppo-text-13",
                  children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Add these rules inside your', 'performance-optimisation'), ' ', /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("code", {
                    children: ["server ", '{', " ...", ' ', '}']
                  }), ' ', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('block, then restart Nginx.', 'performance-optimisation')]
                })]
              }), serverRules?.server_type === 'other' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
                className: "wppo-notice wppo-notice--warning wppo-mt-20",
                children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_5__.FontAwesomeIcon, {
                  icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_6__.faExclamationTriangle
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("span", {
                  children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Unrecognised server software. Automatic rules are only available for Apache (.htaccess).', 'performance-optimisation')
                })]
              })]
            }) : null]
          })
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_9__["default"], {
          title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('CDN Settings', 'performance-optimisation'),
          icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_5__.FontAwesomeIcon, {
            icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_6__.faServer
          }),
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
            className: "wppo-field",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("label", {
              className: "wppo-field-label",
              htmlFor: "cdnURL",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('CDN Hostname', 'performance-optimisation')
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("input", {
              className: "wppo-input",
              type: "url",
              id: "cdnURL",
              name: "cdnURL",
              placeholder: "https://cdn.example.com",
              value: settings.cdnURL,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings),
              "aria-describedby": "cdnURL-desc"
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("p", {
              id: "cdnURL-desc",
              className: "wppo-text-muted wppo-mt-10 wppo-text-small",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Enter your CDN hostname. All static asset URLs (JS, CSS, images) will be rewritten to load from this domain, reducing latency for global visitors.', 'performance-optimisation')
            })]
          })
        })]
      }), activeSubTab === 'core' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
        id: "panel-core",
        className: "wppo-stacked-cards",
        role: "tabpanel",
        "aria-labelledby": "tab-core",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_9__["default"], {
          title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Cleanup Core Bloat', 'performance-optimisation'),
          icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_5__.FontAwesomeIcon, {
            icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_6__.faShieldAlt
          }),
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
            className: "wppo-field-group",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_11__["default"], {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Disable Emojis', 'performance-optimisation'),
              description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)("Remove the WordPress emoji script and stylesheet. Saves ~10 KB per page if you don't use emojis in your content.", 'performance-optimisation'),
              name: "disableEmojis",
              checked: settings.disableEmojis,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_11__["default"], {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Disable Embeds', 'performance-optimisation'),
              description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Remove the oEmbed script that allows embedding external content. Saves ~1 HTTP request if you do not embed tweets, YouTube videos, etc.', 'performance-optimisation'),
              name: "disableEmbeds",
              checked: settings.disableEmbeds,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_11__["default"], {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Disable Dashicons (Frontend)', 'performance-optimisation'),
              description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Prevent the WordPress admin icon font from loading on the frontend for logged-out users. Only disable if your theme does not use Dashicons.', 'performance-optimisation'),
              name: "disableDashicons",
              checked: settings.disableDashicons,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_11__["default"], {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Disable XML-RPC', 'performance-optimisation'),
              description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Block the XML-RPC endpoint (xmlrpc.php). Reduces attack surface and server load. Only disable if you do not use Jetpack, mobile apps, or remote publishing.', 'performance-optimisation'),
              name: "disableXMLRPC",
              checked: settings.disableXMLRPC,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_11__["default"], {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Load Block Assets On Demand', 'performance-optimisation'),
              description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Only load block CSS and JavaScript when blocks are actually used on the page. On WordPress 6.8 you must enable this toggle; on WordPress 6.9 and later classic themes load block assets on demand by default.', 'performance-optimisation'),
              name: "blockAssetsOnDemand",
              checked: settings.blockAssetsOnDemand,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_11__["default"], {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Load Combined Core Block Styles', 'performance-optimisation'),
              description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Load the combined core block stylesheet (wp-block-library) for compatibility with shortcodes or widgets that enqueue styles while content renders. Overrides on-demand block assets. Requires WordPress 6.9+.', 'performance-optimisation'),
              name: "loadAllCoreBlockAssets",
              checked: settings.loadAllCoreBlockAssets,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
            })]
          })
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_9__["default"], {
          title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Heartbeat Control', 'performance-optimisation'),
          icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_5__.FontAwesomeIcon, {
            icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_6__.faRocket
          }),
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("div", {
            className: "wppo-field",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("label", {
              className: "wppo-field-label",
              htmlFor: "heartbeatControl",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('API Frequency', 'performance-optimisation')
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsxs)("select", {
              className: "wppo-select",
              id: "heartbeatControl",
              name: "heartbeatControl",
              value: settings.heartbeatControl,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings),
              "aria-describedby": "heartbeatControl-desc",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("option", {
                value: "default",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Default Mode', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("option", {
                value: "60s",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Reduce Frequency (60s)', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("option", {
                value: "disable_ext",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Disable on Frontend', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("option", {
                value: "disable_all",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Disable Everywhere', 'performance-optimisation')
              })]
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_14__.jsx)("p", {
              id: "heartbeatControl-desc",
              className: "wppo-text-muted wppo-mt-12 wppo-text-13",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Restricting the Heartbeat API reduces server CPU usage by limiting polling.', 'performance-optimisation')
            })]
          })
        })]
      })]
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (FileOptimization);

/***/ },

/***/ "./src/components/common/FeatureCard.js"
/*!**********************************************!*\
  !*** ./src/components/common/FeatureCard.js ***!
  \**********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__);

/**
 * FeatureCard — Standardized card wrapper for every settings group.
 *
 * @param {Object}                    props             Component props.
 * @param {string}                    [props.title]     Optional card heading.
 * @param {import('react').ReactNode} [props.icon]      Optional icon beside the title.
 * @param {import('react').ReactNode} [props.actions]   Buttons / links in the card header.
 * @param {import('react').ReactNode} [props.footer]    Buttons / links in the card footer.
 * @param {import('react').ReactNode} props.children    Card body content.
 * @param {string}                    [props.className] Extra CSS classes.
 */
const FeatureCard = ({
  title,
  icon,
  actions,
  footer,
  children,
  className
}) => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsxs)("div", {
  className: `wppo-feature-card ${className || ''}`.trim(),
  children: [(title || actions) && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsxs)("div", {
    className: "wppo-feature-card__header",
    children: [title && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsxs)("h3", {
      children: [icon, title]
    }), actions && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsx)("div", {
      className: "wppo-feature-card__header-actions",
      children: actions
    })]
  }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsx)("div", {
    className: "wppo-feature-card__body",
    children: children
  }), footer && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsx)("div", {
    className: "wppo-feature-card__footer",
    children: footer
  })]
});
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (FeatureCard);

/***/ },

/***/ "./src/components/common/FeatureHeader.js"
/*!************************************************!*\
  !*** ./src/components/common/FeatureHeader.js ***!
  \************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__);

/**
 * FeatureHeader — Consistent hero section for every tab.
 *
 * @param {Object}                    props               Component props.
 * @param {string}                    props.title         Page heading.
 * @param {string}                    [props.description] Short subtitle text.
 * @param {import('react').ReactNode} [props.status]      Optional status badge / indicator.
 * @param {import('react').ReactNode} [props.actions]     Buttons rendered on the right.
 * @param {import('react').ReactNode} [props.children]    Extra content below the header row.
 */
const FeatureHeader = ({
  title,
  description,
  status,
  actions,
  children
}) => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsxs)("div", {
  className: "wppo-feature-header",
  children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsxs)("div", {
    className: "wppo-feature-header__main",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsxs)("div", {
      className: "wppo-feature-header__title",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsx)("h2", {
        children: title
      }), description && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsx)("p", {
        children: description
      }), status && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsx)("div", {
        className: "wppo-feature-header__status",
        children: status
      })]
    }), actions && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsx)("div", {
      className: "wppo-feature-header__actions",
      children: actions
    })]
  }), children && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsx)("div", {
    className: "wppo-feature-header__extra",
    children: children
  })]
});
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (FeatureHeader);

/***/ },

/***/ "./src/components/common/LoadingSubmitButton.js"
/*!******************************************************!*\
  !*** ./src/components/common/LoadingSubmitButton.js ***!
  \******************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @fortawesome/react-fontawesome */ "./node_modules/@fortawesome/react-fontawesome/index.es.js");
/* harmony import */ var _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @fortawesome/free-solid-svg-icons */ "./node_modules/@fortawesome/free-solid-svg-icons/index.mjs");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__);



/**
 * A reusable submit button with loading state support.
 *
 * @param {Object}               props              Component props.
 * @param {boolean}              props.isLoading    Whether the button is in a loading state.
 * @param {string}               props.label        The label to show when not loading.
 * @param {string}               props.loadingLabel The label to show when loading.
 * @param {string}               props.className    Additional CSS classes.
 * @param {string}               props.type         Button type (default: 'submit').
 * @param {boolean}              props.disabled     Whether the button is disabled (default: isLoading).
 * @param {Object}               props.rest         Any other button props.
 * @param {import('react').Node} props.children     The child elements.
 */

const LoadingSubmitButton = ({
  isLoading,
  label,
  loadingLabel,
  className = 'wppo-button wppo-button--primary',
  type = 'submit',
  disabled,
  children,
  ...rest
}) => {
  const isDisabled = Boolean(disabled) || Boolean(isLoading);
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsxs)("button", {
    type: type,
    className: className,
    disabled: isDisabled,
    "aria-busy": isLoading,
    ...rest,
    children: [isLoading && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_0__.FontAwesomeIcon, {
      icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_1__.faSpinner,
      spin: true,
      "aria-hidden": "true",
      style: {
        marginRight: '8px'
      }
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)("span", {
      role: "status",
      "aria-live": "polite",
      children: isLoading ? loadingLabel || children : label || children
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (LoadingSubmitButton);

/***/ },

/***/ "./src/components/common/NoticeBanner.js"
/*!***********************************************!*\
  !*** ./src/components/common/NoticeBanner.js ***!
  \***********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @fortawesome/react-fontawesome */ "./node_modules/@fortawesome/react-fontawesome/index.es.js");
/* harmony import */ var _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @fortawesome/free-solid-svg-icons */ "./node_modules/@fortawesome/free-solid-svg-icons/index.mjs");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__);
/**
 * Shared presentational notice banner.
 *
 * Renders the `.wppo-notice` markup used across the admin SPA with the
 * correct modifier class, icon, ARIA live region semantics and an optional
 * dismiss button. Pair with the `useNotice()` hook for state and timing.
 *
 * @since 1.10.0
 */




const NoticeBanner = ({
  type = 'info',
  message = '',
  onDismiss,
  className
}) => {
  if (!message) {
    return null;
  }
  const icon = type === 'success' ? _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faCheckCircle : _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faExclamationTriangle;
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsxs)("div", {
    className: `wppo-notice wppo-notice--${type}${className ? ` ${className}` : ''}`,
    role: "alert",
    "aria-live": type === 'error' ? 'assertive' : 'polite',
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsxs)("div", {
      className: "wppo-notice__content",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
        icon: icon
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)("span", {
        children: message
      })]
    }), onDismiss && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)("button", {
      type: "button",
      className: "wppo-notice__dismiss",
      onClick: onDismiss,
      "aria-label": (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Dismiss', 'performance-optimisation'),
      children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
        icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faTimes
      })
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (NoticeBanner);

/***/ },

/***/ "./src/components/common/SwitchField.js"
/*!**********************************************!*\
  !*** ./src/components/common/SwitchField.js ***!
  \**********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/components */ "@wordpress/components");
/* harmony import */ var _wordpress_components__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_components__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__);


/**
 * SwitchField — Accessible toggle switch with label and description.
 * Uses WordPress ToggleControl for native WP styling + accessibility.
 *
 * @param {Object}   props               Component props.
 * @param {string}   props.label         Visible heading for the switch.
 * @param {string}   [props.description] Subtitle text.
 * @param {string}   props.name          Input name attribute.
 * @param {boolean}  props.checked       Whether the switch is on.
 * @param {Function} props.onChange      Change handler (receives synthetic event).
 * @param {boolean}  [props.showLabel]   Whether to show the label.
 * @param {boolean}  [props.disabled]    Whether the switch is disabled.
 */

const SwitchField = ({
  label,
  description,
  name,
  checked,
  onChange,
  showLabel = true,
  disabled = false
}) => {
  const handleToggle = newValue => {
    // Synthesize an event-like object so existing handleChange() util works unchanged.
    onChange({
      target: {
        name,
        type: 'checkbox',
        checked: newValue
      }
    });
  };
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsxs)("div", {
    className: "wppo-switch-field",
    children: [(showLabel || description) && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsxs)("div", {
      className: "wppo-switch-field__info",
      children: [showLabel && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("span", {
        className: "wppo-switch-field__label",
        children: label
      }), description && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("p", {
        className: "wppo-text-muted",
        children: description
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)(_wordpress_components__WEBPACK_IMPORTED_MODULE_0__.ToggleControl, {
      __nextHasNoMarginBottom: true,
      checked: checked,
      onChange: handleToggle,
      label: label,
      hideLabelFromVision: true,
      disabled: disabled
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (SwitchField);

/***/ },

/***/ "./src/components/common/Tooltip.js"
/*!******************************************!*\
  !*** ./src/components/common/Tooltip.js ***!
  \******************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @fortawesome/react-fontawesome */ "./node_modules/@fortawesome/react-fontawesome/index.es.js");
/* harmony import */ var _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @fortawesome/free-solid-svg-icons */ "./node_modules/@fortawesome/free-solid-svg-icons/index.mjs");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__);
/**
 * Tooltip component.
 *
 * A simple, lightweight tooltip that displays on hover.
 * Uses CSS for positioning and visibility.
 *
 * @param {Object}                    props
 * @param {string}                    props.content  The tooltip text.
 * @param {import('react').ReactNode} props.children The element that triggers the tooltip.
 *
 * @since 1.5.0
 */




const Tooltip = ({
  content,
  children
}) => {
  const [visible, setVisible] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const id = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useId)();
  if (!content) {
    return children;
  }
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsxs)("span", {
    className: `wppo-tooltip-container${visible ? ' wppo-tooltip-container--visible' : ''}`,
    tabIndex: "0",
    "aria-describedby": id,
    onFocus: () => setVisible(true),
    onBlur: () => setVisible(false),
    onMouseEnter: () => setVisible(true),
    onMouseLeave: () => setVisible(false),
    children: [children || /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
      icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faInfoCircle,
      className: "wppo-tooltip-icon",
      "aria-hidden": "true"
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)("span", {
      className: "wppo-tooltip-content",
      role: "tooltip",
      id: id,
      children: content
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (Tooltip);

/***/ },

/***/ "./src/lib/useNotice.js"
/*!******************************!*\
  !*** ./src/lib/useNotice.js ***!
  \******************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/**
 * Shared hook for scoped feedback notices.
 *
 * Centralises the divergent per-component notification state and
 * auto-dismiss timer logic into one pattern backed by the
 * NoticeBanner presentational component.
 *
 * Each component that talks to the REST API previously reinvented its
 * own feedback state (`notification`, `announcement`, `error`, `actionMsg`).
 * Use this hook instead:
 *
 * ```js
 * const { notice, notify, dismiss } = useNotice();
 * notify( { type: 'success', message: 'Saved.', durationMs: 5000 } );
 * ```
 *
 * @since 1.10.0
 * @return {{ notice: ?Object, notify: Function, dismiss: Function }}
 *   - `notice`:  `{ type, message }` or `null`.
 *   - `notify`:  `( { type, message, durationMs? } )` — shows a notice and
 *                optionally auto-dismisses it after `durationMs`.
 *   - `dismiss`: `() => void` — clears the notice and any pending timer.
 */

const useNotice = () => {
  const [notice, setNotice] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const timerRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(null);
  const clearTimer = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(() => {
    if (timerRef.current) {
      clearTimeout(timerRef.current);
      timerRef.current = null;
    }
  }, []);

  /**
   * Clear the current notice and any pending auto-dismiss timer.
   */
  const dismiss = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(() => {
    clearTimer();
    setNotice(null);
  }, [clearTimer]);

  /**
   * Show a notice.
   *
   * @param {Object} opts              Notice options.
   * @param {string} opts.type         'error' | 'success' | 'warning' | 'info'.
   * @param {string} opts.message      Notice text.
   * @param {number} [opts.durationMs] Optional auto-dismiss delay in milliseconds.
   */
  const notify = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(({
    type,
    message,
    durationMs
  }) => {
    clearTimer();
    setNotice({
      type,
      message
    });
    if (durationMs) {
      timerRef.current = setTimeout(() => {
        timerRef.current = null;
        setNotice(null);
      }, durationMs);
    }
  }, [clearTimer]);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    return clearTimer;
  }, [clearTimer]);
  return {
    notice,
    notify,
    dismiss
  };
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (useNotice);

/***/ },

/***/ "./src/lib/util.js"
/*!*************************!*\
  !*** ./src/lib/util.js ***!
  \*************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   handleChange: () => (/* binding */ handleChange)
/* harmony export */ });
const handleChange = setSettings => e => {
  const {
    name,
    type,
    value,
    checked
  } = e.target;
  setSettings(prevState => ({
    ...prevState,
    [name]: 'checkbox' === type ? checked : value
  }));
};

/***/ }

}]);
//# sourceMappingURL=tab-file-optimization.js.map?ver=c2c2020f03ce4fa36bdb