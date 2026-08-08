"use strict";
(globalThis["webpackChunkperformance_optimisation"] ||= []).push([["tab-preload-settings"],{

/***/ "./src/components/PreloadSettings.js"
/*!*******************************************!*\
  !*** ./src/components/PreloadSettings.js ***!
  \*******************************************/
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
/* harmony import */ var _common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./common/LoadingSubmitButton */ "./src/components/common/LoadingSubmitButton.js");
/* harmony import */ var _common_SwitchField__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! ./common/SwitchField */ "./src/components/common/SwitchField.js");
/* harmony import */ var _fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_7__ = __webpack_require__(/*! @fortawesome/react-fontawesome */ "./node_modules/@fortawesome/react-fontawesome/index.es.js");
/* harmony import */ var _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_8__ = __webpack_require__(/*! @fortawesome/free-solid-svg-icons */ "./node_modules/@fortawesome/free-solid-svg-icons/index.mjs");
/* harmony import */ var _common_FeatureHeader__WEBPACK_IMPORTED_MODULE_9__ = __webpack_require__(/*! ./common/FeatureHeader */ "./src/components/common/FeatureHeader.js");
/* harmony import */ var _common_FeatureCard__WEBPACK_IMPORTED_MODULE_10__ = __webpack_require__(/*! ./common/FeatureCard */ "./src/components/common/FeatureCard.js");
/* harmony import */ var _common_NoticeBanner__WEBPACK_IMPORTED_MODULE_11__ = __webpack_require__(/*! ./common/NoticeBanner */ "./src/components/common/NoticeBanner.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__);













const PreloadSettings = ({
  options = {}
}) => {
  const defaultSettings = {
    enablePreloadCache: false,
    excludePreloadCache: 'my-account/(.*)\ncart/(.*)\ncheckout/(.*)',
    preconnect: false,
    preconnectOrigins: '',
    prefetchDNS: false,
    dnsPrefetchOrigins: '',
    preloadFonts: false,
    preloadFontsUrls: '',
    preloadCSS: false,
    preloadCSSUrls: '',
    enableSpeculationRules: false,
    speculationMode: 'prerender',
    speculationEagerness: 'moderate',
    speculationExcludeUrls: '',
    ...options
  };
  const [settings, setSettings] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(defaultSettings);
  const [isLoading, setIsLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.useState)(false);
  const {
    notice,
    notify,
    dismiss
  } = (0,_lib_useNotice__WEBPACK_IMPORTED_MODULE_4__["default"])();
  const handleSubmit = async e => {
    if (e) {
      e.preventDefault();
    }
    setIsLoading(true);
    dismiss();
    try {
      const res = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_3__.apiCall)('update_settings', {
        tab: 'preload_settings',
        settings
      });
      if (res.success) {
        notify({
          type: 'success',
          message: res.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Settings updated successfully.', 'performance-optimisation')
        });
      } else {
        notify({
          type: 'error',
          message: res.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Failed to update settings.', 'performance-optimisation')
        });
      }
    } catch (err) {
      console.error('Failed updating preload settings', err);
      notify({
        type: 'error',
        message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('An unexpected error occurred.', 'performance-optimisation')
      });
    } finally {
      setIsLoading(false);
    }
  };
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
    className: "wppo-dashboard-view",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_FeatureHeader__WEBPACK_IMPORTED_MODULE_9__["default"], {
      title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Preload Settings', 'performance-optimisation'),
      description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Improve perceived performance by pre-connecting to domains and preloading critical assets.', 'performance-optimisation'),
      actions: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_5__["default"], {
        className: "wppo-button wppo-button--primary",
        isLoading: isLoading,
        onClick: handleSubmit,
        label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Save Settings', 'performance-optimisation')
      }),
      children: notice && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_NoticeBanner__WEBPACK_IMPORTED_MODULE_11__["default"], {
        type: notice.type,
        message: notice.message,
        className: "wppo-mb-20"
      })
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("form", {
      onSubmit: handleSubmit,
      className: "wppo-stacked-cards",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_10__["default"], {
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Cache Warm-up', 'performance-optimisation'),
        icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_7__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_8__.faHourglassStart
        }),
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
          className: "wppo-field-group",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_6__["default"], {
            label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Enable Preload Cache', 'performance-optimisation'),
            description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Automatically visit all pages to pre-generate the cache. The first real visitor gets a fast cached response instead of waiting for a cold page build.', 'performance-optimisation'),
            name: "enablePreloadCache",
            checked: settings.enablePreloadCache,
            onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
          }), settings.enablePreloadCache && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
            className: "wppo-mt-20",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("label", {
              className: "wppo-field-label",
              htmlFor: "excludePreloadCache",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Exclude URLs from Cache Warm-up', 'performance-optimisation')
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("textarea", {
              className: "wppo-textarea",
              id: "excludePreloadCache",
              name: "excludePreloadCache",
              rows: "3",
              placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Regex patterns, one per line', 'performance-optimisation'),
              value: settings.excludePreloadCache,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings),
              "aria-describedby": "excludePreloadCache-desc"
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("p", {
              id: "excludePreloadCache-desc",
              className: "wppo-text-muted wppo-mt-10 wppo-text-small",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Skip dynamic pages like cart, checkout, and account pages that should never be cached. Supports regex patterns.', 'performance-optimisation')
            })]
          })]
        })
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_10__["default"], {
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Third-Party Connections', 'performance-optimisation'),
        icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_7__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_8__.faLink
        }),
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
          className: "wppo-field-group",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_6__["default"], {
            label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Preconnect', 'performance-optimisation'),
            description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Open a TCP/TLS connection to third-party origins before the browser needs them. Eliminates connection setup latency for fonts, analytics, and CDN resources.', 'performance-optimisation'),
            name: "preconnect",
            checked: settings.preconnect,
            onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
          }), settings.preconnect && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
            className: "wppo-field",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("label", {
              className: "wppo-field-label",
              htmlFor: "preconnectOrigins",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Preconnect Origins', 'performance-optimisation')
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("textarea", {
              className: "wppo-textarea",
              id: "preconnectOrigins",
              name: "preconnectOrigins",
              rows: "2",
              placeholder: "https://fonts.googleapis.com",
              value: settings.preconnectOrigins,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings),
              "aria-describedby": "preconnectOrigins-desc"
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("p", {
              id: "preconnectOrigins-desc",
              className: "wppo-text-muted wppo-mt-10 wppo-text-small",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('One origin per line. Use full URLs including protocol.', 'performance-optimisation')
            })]
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_6__["default"], {
            label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('DNS Prefetch', 'performance-optimisation'),
            description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Resolve domain names in the background before the browser requests resources from them. Faster than preconnect but only handles DNS — useful for domains you do not need a full connection to immediately.', 'performance-optimisation'),
            name: "prefetchDNS",
            checked: settings.prefetchDNS,
            onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
          }), settings.prefetchDNS && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
            className: "wppo-field",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("label", {
              className: "wppo-field-label",
              htmlFor: "dnsPrefetchOrigins",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('DNS Prefetch Origins', 'performance-optimisation')
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("textarea", {
              className: "wppo-textarea",
              id: "dnsPrefetchOrigins",
              name: "dnsPrefetchOrigins",
              rows: "2",
              placeholder: "example.com",
              value: settings.dnsPrefetchOrigins,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings),
              "aria-describedby": "dnsPrefetchOrigins-desc"
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("p", {
              id: "dnsPrefetchOrigins-desc",
              className: "wppo-text-muted wppo-mt-10 wppo-text-small",
              children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('One hostname per line, without protocol (e.g.', 'performance-optimisation'), ' ', /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("code", {
                children: "cdn.example.com"
              }), ")."]
            })]
          })]
        })
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_10__["default"], {
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Critical Assets Preloading', 'performance-optimisation'),
        icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_7__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_8__.faFont
        }),
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
          className: "wppo-stacked-cards",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
            className: "wppo-field-group",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_6__["default"], {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Preload Fonts', 'performance-optimisation'),
              description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Inject preload hints for critical font files so the browser fetches them at the highest priority. Eliminates the flash of invisible text (FOIT) on first load.', 'performance-optimisation'),
              name: "preloadFonts",
              checked: settings.preloadFonts,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
            }), settings.preloadFonts && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
              className: "wppo-field",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "preloadFontsUrls",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Font URLs to Preload', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("textarea", {
                className: "wppo-textarea",
                id: "preloadFontsUrls",
                name: "preloadFontsUrls",
                rows: "3",
                placeholder: "/wp-content/themes/my-theme/fonts/myfont.woff2",
                value: settings.preloadFontsUrls,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings),
                "aria-describedby": "preloadFontsUrls-desc"
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("p", {
                id: "preloadFontsUrls-desc",
                className: "wppo-text-muted wppo-mt-10 wppo-text-small",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('One URL per line. Prefer .woff2 format for best browser support.', 'performance-optimisation')
              })]
            })]
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
            className: "wppo-field-group",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_6__["default"], {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Preload Critical CSS', 'performance-optimisation'),
              description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Inject preload hints for above-the-fold stylesheets. Ensures critical styles are fetched before the browser renders the page, reducing render-blocking delays.', 'performance-optimisation'),
              name: "preloadCSS",
              checked: settings.preloadCSS,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
            }), settings.preloadCSS && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
              className: "wppo-field",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "preloadCSSUrls",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('CSS URLs to Preload', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("textarea", {
                className: "wppo-textarea",
                id: "preloadCSSUrls",
                name: "preloadCSSUrls",
                rows: "3",
                placeholder: "/wp-content/themes/my-theme/style.css",
                value: settings.preloadCSSUrls,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings),
                "aria-describedby": "preloadCSSUrls-desc"
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("p", {
                id: "preloadCSSUrls-desc",
                className: "wppo-text-muted wppo-mt-10 wppo-text-small",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('One URL per line. Only add stylesheets needed for above-the-fold content.', 'performance-optimisation')
              })]
            })]
          })]
        })
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_10__["default"], {
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Speculative Loading', 'performance-optimisation'),
        icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_7__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_8__.faRocket
        }),
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
          className: "wppo-field-group",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_6__["default"], {
            label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Enable Speculative Loading', 'performance-optimisation'),
            description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('When enabled, browsers may prerender or prefetch linked pages before navigation for near-instant load times.', 'performance-optimisation'),
            name: "enableSpeculationRules",
            checked: settings.enableSpeculationRules,
            onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
          }), settings.enableSpeculationRules && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.Fragment, {
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
              className: "wppo-field",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "speculationMode",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Speculation Mode', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("select", {
                className: "wppo-select",
                id: "speculationMode",
                name: "speculationMode",
                value: settings.speculationMode,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings),
                children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("option", {
                  value: "prefetch",
                  children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Prefetch (faster navigation, lower resource usage)', 'performance-optimisation')
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("option", {
                  value: "prerender",
                  children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Prerender (instant navigation, higher resource usage)', 'performance-optimisation')
                })]
              })]
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
              className: "wppo-field",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "speculationEagerness",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Eagerness', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("select", {
                className: "wppo-select",
                id: "speculationEagerness",
                name: "speculationEagerness",
                value: settings.speculationEagerness,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings),
                children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("option", {
                  value: "conservative",
                  children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Conservative (only on hover/touch)', 'performance-optimisation')
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("option", {
                  value: "moderate",
                  children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Moderate (on hover + nearby links)', 'performance-optimisation')
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("option", {
                  value: "eager",
                  children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Eager (immediately on page load)', 'performance-optimisation')
                })]
              })]
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
              className: "wppo-field",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "speculationExcludeUrls",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Exclude URLs from Speculation', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("textarea", {
                className: "wppo-textarea",
                id: "speculationExcludeUrls",
                name: "speculationExcludeUrls",
                rows: "3",
                placeholder: "/my-plugin/*\\n/excluded-path/*",
                value: settings.speculationExcludeUrls,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings),
                "aria-describedby": "speculationExcludeUrls-desc"
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("p", {
                id: "speculationExcludeUrls-desc",
                className: "wppo-text-muted wppo-mt-10 wppo-text-small",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Add URL patterns to exclude from speculative loading. Supports wildcard (*) patterns. WooCommerce cart, checkout, and account pages are excluded automatically.', 'performance-optimisation')
              })]
            })]
          })]
        })
      })]
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (PreloadSettings);

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
//# sourceMappingURL=tab-preload-settings.js.map?ver=782df592895bb48ccc1e