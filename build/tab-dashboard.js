"use strict";
(globalThis["webpackChunkperformance_optimisation"] ||= []).push([["tab-dashboard"],{

/***/ "./src/components/AiPanel.js"
/*!***********************************!*\
  !*** ./src/components/AiPanel.js ***!
  \***********************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _lib_apiRequest__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../lib/apiRequest */ "./src/lib/apiRequest.js");
/* harmony import */ var _lib_useNotice__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../lib/useNotice */ "./src/lib/useNotice.js");
/* harmony import */ var _common_FeatureCard__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ./common/FeatureCard */ "./src/components/common/FeatureCard.js");
/* harmony import */ var _common_SwitchField__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./common/SwitchField */ "./src/components/common/SwitchField.js");
/* harmony import */ var _common_NoticeBanner__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! ./common/NoticeBanner */ "./src/components/common/NoticeBanner.js");
/* harmony import */ var _common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_7__ = __webpack_require__(/*! ./common/LoadingSubmitButton */ "./src/components/common/LoadingSubmitButton.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__);









/**
 * AI Adaptive panel (N1).
 *
 * Toggle + Learn + suggestions with one-click Apply (never auto-enables).
 *
 * @since NEXT
 */

const AiPanel = () => {
  const initial = typeof wppoSettings !== 'undefined' ? wppoSettings?.settings?.ai_adaptive || {} : {};
  const [enabled, setEnabled] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(!!initial.enabled);
  const [saving, setSaving] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [learning, setLearning] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [model, setModel] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [suggestions, setSuggestions] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)([]);
  const {
    notice,
    notify,
    dismiss
  } = (0,_lib_useNotice__WEBPACK_IMPORTED_MODULE_3__["default"])();
  const fetchModel = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(async () => {
    try {
      const res = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_2__.apiCall)('ai_model', {}, 'GET');
      if (res.success) {
        setModel(res.data);
      }
    } catch {
      // ignore
    }
  }, []);
  const fetchSuggestions = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(async () => {
    try {
      const res = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_2__.apiCall)('ai_suggestions', {}, 'GET');
      if (res.success && res.data?.suggestions) {
        setSuggestions(res.data.suggestions);
      }
    } catch {
      // ignore
    }
  }, []);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    fetchModel();
    fetchSuggestions();
  }, [fetchModel, fetchSuggestions]);
  const handleSave = async () => {
    setSaving(true);
    dismiss();
    try {
      const response = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_2__.apiCall)('update_settings', {
        tab: 'ai_adaptive',
        settings: {
          enabled
        }
      });
      if (response.success) {
        if (typeof wppoSettings !== 'undefined' && wppoSettings.settings) {
          wppoSettings.settings.ai_adaptive = {
            enabled
          };
        }
        notify({
          type: 'success',
          message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('AI Adaptive settings saved.', 'performance-optimisation'),
          durationMs: 3000
        });
        fetchSuggestions();
      } else {
        notify({
          type: 'error',
          message: response.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to save AI settings.', 'performance-optimisation')
        });
      }
    } catch {
      notify({
        type: 'error',
        message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to save AI settings.', 'performance-optimisation')
      });
    } finally {
      setSaving(false);
    }
  };
  const handleLearn = async () => {
    setLearning(true);
    dismiss();
    try {
      const res = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_2__.apiCall)('ai_learn', {}, 'POST');
      if (res.success) {
        setModel(res.data);
        notify({
          type: 'success',
          message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('AI model updated.', 'performance-optimisation'),
          durationMs: 3000
        });
        fetchSuggestions();
      } else {
        notify({
          type: 'error',
          message: res.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to learn.', 'performance-optimisation')
        });
      }
    } catch {
      notify({
        type: 'error',
        message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to learn.', 'performance-optimisation')
      });
    } finally {
      setLearning(false);
    }
  };
  const handleApply = async suggestion => {
    const payload = suggestion.ai_payload;
    if (!payload) {
      return;
    }
    try {
      const currentTabSettings = typeof wppoSettings !== 'undefined' ? wppoSettings.settings?.[payload.tab] || {} : {};
      const merged = {
        ...currentTabSettings,
        ...payload.settings
      };
      const res = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_2__.apiCall)('update_settings', {
        tab: payload.tab,
        settings: merged
      });
      if (res.success) {
        if (typeof wppoSettings !== 'undefined' && wppoSettings.settings) {
          wppoSettings.settings[payload.tab] = merged;
        }
        notify({
          type: 'success',
          message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Suggestion applied.', 'performance-optimisation'),
          durationMs: 3000
        });
      } else {
        notify({
          type: 'error',
          message: res.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to apply suggestion.', 'performance-optimisation')
        });
      }
    } catch {
      notify({
        type: 'error',
        message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to apply suggestion.', 'performance-optimisation')
      });
    }
  };
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsxs)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_4__["default"], {
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('AI Adaptive', 'performance-optimisation'),
    icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("i", {
      className: "fas fa-brain"
    }),
    children: [notice && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)(_common_NoticeBanner__WEBPACK_IMPORTED_MODULE_6__["default"], {
      type: notice.type,
      message: notice.message,
      onDismiss: dismiss
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_5__["default"], {
      label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Enable AI Adaptive', 'performance-optimisation'),
      description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Learn from RUM and trends to suggest script excludes and speculation prefetch. Never auto-enables — suggestions require confirmation.', 'performance-optimisation'),
      name: "aiAdaptiveEnabled",
      checked: enabled,
      onChange: e => setEnabled(e.target.checked)
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("p", {
      className: "wppo-text-muted wppo-text-small",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Toggle is gated by wppo_ai_adaptive_enabled filter.', 'performance-optimisation')
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsxs)("div", {
      className: "wppo-feature-card__footer",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_7__["default"], {
        className: "wppo-button wppo-button--primary",
        onClick: handleSave,
        isLoading: saving,
        label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Save AI Settings', 'performance-optimisation'),
        loadingLabel: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Saving…', 'performance-optimisation')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_7__["default"], {
        className: "wppo-button wppo-button--secondary wppo-ml-8",
        onClick: handleLearn,
        isLoading: learning,
        label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Learn Now', 'performance-optimisation'),
        loadingLabel: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Learning…', 'performance-optimisation')
      })]
    }), model && model.updated_at && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsxs)("p", {
      className: "wppo-text-muted wppo-text-small wppo-mt-12",
      children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Model updated:', 'performance-optimisation'), ' ', new Date(model.updated_at * 1000).toLocaleString(), ' ', model.source ? `(${model.source})` : '']
    }), suggestions.length > 0 && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsxs)("div", {
      className: "wppo-stacked-cards wppo-mt-16",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("h4", {
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('AI Suggestions', 'performance-optimisation')
      }), suggestions.map(s => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsxs)("div", {
        className: "wppo-suggestion-card wppo-suggestion-card--needs_improvement",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsxs)("div", {
          className: "wppo-suggestion-card__header",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("span", {
            className: "wppo-suggestion-card__description",
            children: s.description
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("span", {
            className: "wppo-status-badge wppo-status-badge--warning",
            children: s.status
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsxs)("div", {
          className: "wppo-suggestion-card__body",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("span", {
            className: "wppo-suggestion-card__value",
            children: String(s.value)
          }), s.ai_payload && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("button", {
            type: "button",
            className: "wppo-button wppo-button--sm wppo-button--primary",
            onClick: () => handleApply(s),
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Apply', 'performance-optimisation')
          })]
        })]
      }, s.metric))]
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (AiPanel);

/***/ },

/***/ "./src/components/AutoloadedOptions.js"
/*!*********************************************!*\
  !*** ./src/components/AutoloadedOptions.js ***!
  \*********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @fortawesome/react-fontawesome */ "./node_modules/@fortawesome/react-fontawesome/index.es.js");
/* harmony import */ var _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! @fortawesome/free-solid-svg-icons */ "./node_modules/@fortawesome/free-solid-svg-icons/index.mjs");
/* harmony import */ var _lib_apiRequest__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ../lib/apiRequest */ "./src/lib/apiRequest.js");
/* harmony import */ var _common_FeatureCard__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./common/FeatureCard */ "./src/components/common/FeatureCard.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__);
/**
 * AutoloadedOptions component.
 *
 * Lists the largest autoloaded options (option bloat that inflates every page
 * load). Fetches GET /autoloaded_options on mount.
 *
 * @since 2.18.0
 */








/**
 * @return {Element} The autoloaded-options card.
 */

const AutoloadedOptions = () => {
  const [options, setOptions] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)([]);
  const [loading, setLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(true);
  const [error, setError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const load = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_4__.apiCall)('autoloaded_options?limit=20', {}, 'GET');
      if (response.success && response.data?.options) {
        setOptions(response.data.options);
      } else {
        setError(response.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to load autoloaded options.', 'performance-optimisation'));
      }
    } catch (loadError) {
      setError((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to load autoloaded options.', 'performance-optimisation'));
      console.error('Error fetching autoloaded options:', loadError);
    } finally {
      setLoading(false);
    }
  }, []);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    load();
  }, [load]);
  const formatSize = bytes => {
    if (bytes < 1024) {
      return `${bytes} B`;
    }
    return `${(bytes / 1024).toFixed(1)} KB`;
  };
  let body;
  if (error) {
    body = /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("p", {
      className: "wppo-text-muted",
      children: error
    });
  } else if (options.length === 0 && !loading) {
    body = /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("p", {
      className: "wppo-text-muted",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('No autoloaded options found.', 'performance-optimisation')
    });
  } else {
    body = /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("ul", {
      className: "wppo-autoloaded-options",
      children: options.map(option => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("li", {
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("code", {
          children: option.option_name
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("span", {
          className: "wppo-text-muted",
          children: formatSize(option.size)
        })]
      }, option.option_name))
    });
  }
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_5__["default"], {
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Autoloaded Options', 'performance-optimisation'),
    icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_2__.FontAwesomeIcon, {
      icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_3__.faDatabase
    }),
    actions: loading && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_2__.FontAwesomeIcon, {
      icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_3__.faSpinner,
      spin: true,
      "aria-label": (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Loading…', 'performance-optimisation')
    }),
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("p", {
      className: "wppo-text-muted",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('The largest options loaded on every request. Reducing these improves TTFB on shared hosting.', 'performance-optimisation')
    }), body, options.length > 0 && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("p", {
      className: "wppo-text-muted wppo-text-small",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.sprintf)(/* translators: %d: number of options listed */
      (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Showing %d options.', 'performance-optimisation'), options.length)
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (AutoloadedOptions);

/***/ },

/***/ "./src/components/Dashboard.js"
/*!*************************************!*\
  !*** ./src/components/Dashboard.js ***!
  \*************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _lib_apiRequest__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ../lib/apiRequest */ "./src/lib/apiRequest.js");
/* harmony import */ var _lib_useNotice__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../lib/useNotice */ "./src/lib/useNotice.js");
/* harmony import */ var _common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./common/LoadingSubmitButton */ "./src/components/common/LoadingSubmitButton.js");
/* harmony import */ var _common_ConfirmDialog__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ./common/ConfirmDialog */ "./src/components/common/ConfirmDialog.js");
/* harmony import */ var _common_FeatureHeader__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./common/FeatureHeader */ "./src/components/common/FeatureHeader.js");
/* harmony import */ var _common_FeatureCard__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! ./common/FeatureCard */ "./src/components/common/FeatureCard.js");
/* harmony import */ var _common_SwitchField__WEBPACK_IMPORTED_MODULE_7__ = __webpack_require__(/*! ./common/SwitchField */ "./src/components/common/SwitchField.js");
/* harmony import */ var _common_CheckboxOption__WEBPACK_IMPORTED_MODULE_8__ = __webpack_require__(/*! ./common/CheckboxOption */ "./src/components/common/CheckboxOption.js");
/* harmony import */ var _common_NoticeBanner__WEBPACK_IMPORTED_MODULE_9__ = __webpack_require__(/*! ./common/NoticeBanner */ "./src/components/common/NoticeBanner.js");
/* harmony import */ var _PerformanceAudit__WEBPACK_IMPORTED_MODULE_10__ = __webpack_require__(/*! ./PerformanceAudit */ "./src/components/PerformanceAudit.js");
/* harmony import */ var _PageSpeedPanel__WEBPACK_IMPORTED_MODULE_11__ = __webpack_require__(/*! ./PageSpeedPanel */ "./src/components/PageSpeedPanel.js");
/* harmony import */ var _WebVitalsTrends__WEBPACK_IMPORTED_MODULE_12__ = __webpack_require__(/*! ./WebVitalsTrends */ "./src/components/WebVitalsTrends.js");
/* harmony import */ var _WebVitalsRum__WEBPACK_IMPORTED_MODULE_13__ = __webpack_require__(/*! ./WebVitalsRum */ "./src/components/WebVitalsRum.js");
/* harmony import */ var _SuggestionsPanel__WEBPACK_IMPORTED_MODULE_14__ = __webpack_require__(/*! ./SuggestionsPanel */ "./src/components/SuggestionsPanel.js");
/* harmony import */ var _SystemInfo__WEBPACK_IMPORTED_MODULE_15__ = __webpack_require__(/*! ./SystemInfo */ "./src/components/SystemInfo.js");
/* harmony import */ var _AutoloadedOptions__WEBPACK_IMPORTED_MODULE_16__ = __webpack_require__(/*! ./AutoloadedOptions */ "./src/components/AutoloadedOptions.js");
/* harmony import */ var _LlmsPanel__WEBPACK_IMPORTED_MODULE_17__ = __webpack_require__(/*! ./LlmsPanel */ "./src/components/LlmsPanel.js");
/* harmony import */ var _AiPanel__WEBPACK_IMPORTED_MODULE_18__ = __webpack_require__(/*! ./AiPanel */ "./src/components/AiPanel.js");
/* harmony import */ var _EdgeCachePanel__WEBPACK_IMPORTED_MODULE_19__ = __webpack_require__(/*! ./EdgeCachePanel */ "./src/components/EdgeCachePanel.js");
/* harmony import */ var _ImageOptimizationCard__WEBPACK_IMPORTED_MODULE_20__ = __webpack_require__(/*! ./ImageOptimizationCard */ "./src/components/ImageOptimizationCard.js");
/* harmony import */ var _RecentActivityCard__WEBPACK_IMPORTED_MODULE_21__ = __webpack_require__(/*! ./RecentActivityCard */ "./src/components/RecentActivityCard.js");
/* harmony import */ var _WelcomePanel__WEBPACK_IMPORTED_MODULE_22__ = __webpack_require__(/*! ./WelcomePanel */ "./src/components/WelcomePanel.js");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_23___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__);
/* harmony import */ var _lib_litespeed__WEBPACK_IMPORTED_MODULE_24__ = __webpack_require__(/*! ../lib/litespeed */ "./src/lib/litespeed.js");
/* harmony import */ var _fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_25__ = __webpack_require__(/*! @fortawesome/react-fontawesome */ "./node_modules/@fortawesome/react-fontawesome/index.es.js");
/* harmony import */ var _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_26__ = __webpack_require__(/*! @fortawesome/free-solid-svg-icons */ "./node_modules/@fortawesome/free-solid-svg-icons/index.mjs");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__);




























/**
 * Normalize wppoSettings.image_info which stores arrays of file paths
 * into the {webp: count, avif: count} shape the component expects.
 * @param {Object} raw - Raw image info object.
 */

const normalizeImageInfo = raw => {
  const normalize = bucket => ({
    webp: Array.isArray(bucket?.webp) ? bucket.webp.length : bucket?.webp || 0,
    avif: Array.isArray(bucket?.avif) ? bucket.avif.length : bucket?.avif || 0
  });
  return {
    completed: normalize(raw?.completed),
    pending: normalize(raw?.pending),
    failed: normalize(raw?.failed)
  };
};
const Dashboard = ({
  activities,
  cacheSettings: propCacheSettings,
  userRoles: propUserRoles,
  onNavigate
}) => {
  // Phase 2 — suggestions state (populated by telemetry scan + PageSpeed scan).
  const [telemetrySuggestions, setTelemetrySuggestions] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)([]);
  const [pagespeedSuggestions, setPagespeedSuggestions] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)([]);
  const [auditUrl, setAuditUrl] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(typeof wppoSettings !== 'undefined' ? wppoSettings?.performance_audit?.homeUrl ?? '' : '');
  // Merge telemetry and PageSpeed suggestions, deduplicating by metric key.
  const allSuggestions = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useMemo)(() => {
    const seen = new Set();
    const merged = [];
    for (const s of [...pagespeedSuggestions, ...telemetrySuggestions]) {
      if (!seen.has(s.metric)) {
        seen.add(s.metric);
        merged.push(s);
      }
    }
    return merged;
  }, [telemetrySuggestions, pagespeedSuggestions]);

  // Reset suggestions when auditUrl changes to prevent stale results from merging.
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    setTelemetrySuggestions([]);
    setPagespeedSuggestions([]);
  }, [auditUrl]);

  // Initialize state
  const [state, setState] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)({
    totalCacheSize: typeof wppoSettings !== 'undefined' ? wppoSettings?.cache_size ?? '0 B' : '0 B',
    totalJs: typeof wppoSettings !== 'undefined' ? wppoSettings?.total_js_css?.js ?? 0 : 0,
    totalCss: typeof wppoSettings !== 'undefined' ? wppoSettings?.total_js_css?.css ?? 0 : 0,
    imageInfo: normalizeImageInfo(typeof wppoSettings !== 'undefined' ? wppoSettings?.image_info : {}),
    dbCounts: {},
    loading: {
      clear_cache: false,
      optimize_images: false,
      remove_images: false,
      db_counts: true
    }
  });

  // Logged-in user cache settings — prefer props from App.js, fallback to global for direct mounts/tests.
  const cacheSettings = propCacheSettings ?? (typeof wppoSettings !== 'undefined' ? wppoSettings?.settings?.cache_settings || {} : {});
  const userRoles = propUserRoles ?? (typeof wppoSettings !== 'undefined' ? wppoSettings?.userRoles || {} : {});
  const [pageCacheEnabled, setPageCacheEnabled] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(!!cacheSettings.enableCache);
  const [savingPageCache, setSavingPageCache] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [cacheLife, setCacheLife] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(Number(cacheSettings.cacheLife ?? 0));
  const [loggedInCacheEnabled, setLoggedInCacheEnabled] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(!!cacheSettings.enableLoggedInCache);
  const [loggedInCacheRoles, setLoggedInCacheRoles] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(Array.isArray(cacheSettings.loggedInCacheRoles) ? cacheSettings.loggedInCacheRoles : []);
  const [savingLoggedInCache, setSavingLoggedInCache] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);

  // CDN cache purge (Cloudflare / Varnish).
  const [cdnPurgeService, setCdnPurgeService] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(cacheSettings.cdnPurgeService ?? 'none');
  const [cloudflareZoneId, setCloudflareZoneId] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(cacheSettings.cloudflareZoneId ?? '');
  const [varnishPurgeUrls, setVarnishPurgeUrls] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(Array.isArray(cacheSettings.varnishPurgeUrls) ? cacheSettings.varnishPurgeUrls.join('\n') : '');
  const [savingCdnPurge, setSavingCdnPurge] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);

  // Sync derived state when cacheSettings changes (e.g. parent App.js
  // re-fetches settings or apiCall mutates global wppoSettings).
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    setPageCacheEnabled(!!cacheSettings.enableCache);
    setCacheLife(Number(cacheSettings.cacheLife ?? 0));
    setLoggedInCacheEnabled(!!cacheSettings.enableLoggedInCache);
    setLoggedInCacheRoles(Array.isArray(cacheSettings.loggedInCacheRoles) ? cacheSettings.loggedInCacheRoles : []);
    setCdnPurgeService(cacheSettings.cdnPurgeService ?? 'none');
    setCloudflareZoneId(cacheSettings.cloudflareZoneId ?? '');
    setVarnishPurgeUrls(Array.isArray(cacheSettings.varnishPurgeUrls) ? cacheSettings.varnishPurgeUrls.join('\n') : '');
  }, [cacheSettings.enableCache, cacheSettings.cacheLife, cacheSettings.enableLoggedInCache, cacheSettings.loggedInCacheRoles, cacheSettings.cdnPurgeService, cacheSettings.cloudflareZoneId, cacheSettings.varnishPurgeUrls]);
  const [bgProcessing, setBgProcessing] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [bgJobsQueued, setBgJobsQueued] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(0);
  const [imgSavings, setImgSavings] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const pollingRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(null);
  const pollRetryRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(0);
  const submittingRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(false);
  const [confirmRemove, setConfirmRemove] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const {
    notice,
    notify,
    dismiss
  } = (0,_lib_useNotice__WEBPACK_IMPORTED_MODULE_2__["default"])();
  const {
    imageInfo,
    loading,
    totalCacheSize,
    totalJs,
    totalCss,
    dbCounts
  } = state;
  const {
    completed = {},
    pending = {},
    failed = {}
  } = imageInfo;
  const updateState = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(updates => {
    setState(prevState => ({
      ...prevState,
      ...updates
    }));
  }, []);
  const handleLoading = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)((key, isLoading) => {
    setState(prevState => ({
      ...prevState,
      loading: {
        ...prevState.loading,
        [key]: isLoading
      }
    }));
  }, []);
  const fetchDbCounts = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(async () => {
    handleLoading('db_counts', true);
    try {
      const response = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_1__.apiCall)('database_cleanup_counts', {}, 'GET');
      if (response.success && response.data) {
        updateState({
          dbCounts: response.data
        });
      }
    } catch (error) {
      console.error('Error fetching db counts:', error);
      notify({
        type: 'error',
        message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Failed to load database counts.', 'performance-optimisation'),
        durationMs: 5000
      });
    } finally {
      handleLoading('db_counts', false);
    }
  }, [handleLoading, updateState, notify]);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    fetchDbCounts();
  }, [fetchDbCounts]);
  const dbOverheadCount = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useMemo)(() => {
    return Object.values(dbCounts).reduce((sum, val) => sum + (parseInt(val, 10) || 0), 0);
  }, [dbCounts]);
  const pollJobStatus = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(async () => {
    const currentTimeout = pollingRef.current;
    try {
      const response = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_1__.apiCall)('image_job_status', {}, 'GET');
      pollRetryRef.current = 0;
      if (response.success && response.data) {
        const {
          queued_jobs: queuedJobs
        } = response.data;
        setBgJobsQueued(queuedJobs);
        setImgSavings(response.data.savings || null);
        updateState({
          imageInfo: {
            completed: {
              webp: response.data.completed?.webp || 0,
              avif: response.data.completed?.avif || 0
            },
            pending: {
              webp: response.data.pending?.webp || 0,
              avif: response.data.pending?.avif || 0
            },
            failed: {
              webp: response.data.failed?.webp || 0,
              avif: response.data.failed?.avif || 0
            }
          }
        });
        if (queuedJobs === 0) {
          setBgProcessing(false);
          notify({
            type: 'success',
            message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Image optimisation completed.', 'performance-optimisation'),
            durationMs: 5000
          });
          pollingRef.current = null;
          return;
        }
      }
    } catch (error) {
      console.error('Error polling job status:', error);
      pollRetryRef.current++;
      if (pollRetryRef.current >= 5) {
        setBgProcessing(false);
        pollingRef.current = null;
        notify({
          type: 'error',
          message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Status check stopped after repeated failures.', 'performance-optimisation'),
          durationMs: 5000
        });
        return;
      }
      notify({
        type: 'error',
        message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Status check failed. Retrying…', 'performance-optimisation'),
        durationMs: 5000
      });
    }
    if (pollingRef.current === currentTimeout) {
      pollingRef.current = setTimeout(pollJobStatus, 5000);
    }
  }, [updateState, notify]);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    return () => {
      if (pollingRef.current) {
        clearTimeout(pollingRef.current);
      }
    };
  }, []);
  const onClearCache = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(e => {
    e.preventDefault();
    handleLoading('clear_cache', true);
    (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_1__.apiCall)('clear_cache', {
      action: 'clear_cache'
    }).then(data => {
      if (data.success) {
        notify({
          type: 'success',
          message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Cache cleared successfully.', 'performance-optimisation'),
          durationMs: 5000
        });
        updateState({
          totalCacheSize: '0 B',
          totalJs: 0,
          totalCss: 0
        });
      } else {
        notify({
          type: 'error',
          message: data.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Failed to clear cache.', 'performance-optimisation'),
          durationMs: 5000
        });
      }
    }).catch(() => notify({
      type: 'error',
      message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Failed to clear cache.', 'performance-optimisation'),
      durationMs: 5000
    })).finally(() => handleLoading('clear_cache', false));
  }, [handleLoading, updateState, notify]);
  const optimizeImages = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(() => {
    if (loading.optimize_images || bgProcessing || submittingRef.current) {
      return;
    }
    submittingRef.current = true;
    handleLoading('optimize_images', true);
    (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_1__.apiCall)('optimise_image', {}).then(response => {
      if (response.data?.background) {
        // Background (Action Scheduler) path.
        setBgProcessing(true);
        setBgJobsQueued(response.data.jobs_queued || 0);
        notify({
          type: 'success',
          message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Image optimisation started in background.', 'performance-optimisation'),
          durationMs: 5000
        });
        if (pollingRef.current) {
          clearTimeout(pollingRef.current);
        }
        pollingRef.current = setTimeout(pollJobStatus, 5000);
      } else {
        // Synchronous path (Action Scheduler unavailable).
        setBgJobsQueued(0);
        setBgProcessing(false);
        if (response.success && response.data) {
          updateState({
            imageInfo: normalizeImageInfo(response.data)
          });
          notify({
            type: 'success',
            message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Images optimized successfully.', 'performance-optimisation'),
            durationMs: 5000
          });
        }
        if (pollingRef.current) {
          clearTimeout(pollingRef.current);
          pollingRef.current = null;
        }
      }
    }).catch(() => notify({
      type: 'error',
      message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Image optimisation failed.', 'performance-optimisation'),
      durationMs: 5000
    })).finally(() => {
      submittingRef.current = false;
      handleLoading('optimize_images', false);
    });
  }, [handleLoading, pollJobStatus, updateState, notify, bgProcessing, loading.optimize_images]);
  const removeImages = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(() => {
    handleLoading('remove_images', true);
    (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_1__.apiCall)('delete_optimised_image', {}).then(data => {
      if (data.success) {
        setState(prev => ({
          ...prev,
          imageInfo: {
            completed: {
              webp: 0,
              avif: 0
            },
            pending: {
              webp: 0,
              avif: 0
            },
            failed: {
              webp: 0,
              avif: 0
            }
          }
        }));
        notify({
          type: 'success',
          message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Optimized images removed.', 'performance-optimisation'),
          durationMs: 5000
        });
      } else {
        notify({
          type: 'error',
          message: data.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Failed to remove optimized images.', 'performance-optimisation'),
          durationMs: 5000
        });
      }
    }).catch(() => notify({
      type: 'error',
      message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Failed to remove optimized images.', 'performance-optimisation'),
      durationMs: 5000
    })).finally(() => handleLoading('remove_images', false));
  }, [handleLoading, notify]);
  const savePageCacheSettings = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(() => {
    setSavingPageCache(true);
    // Re-read global wppoSettings at call-time to avoid stale closure
    // after prior save mutated it via apiCall's freeze.
    const currentSettings = (typeof wppoSettings !== 'undefined' ? wppoSettings.settings?.cache_settings : null) ?? cacheSettings ?? {};
    (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_1__.apiCall)('update_settings', {
      tab: 'cache_settings',
      settings: {
        ...currentSettings,
        enableCache: pageCacheEnabled,
        cacheLife
      }
    }).then(response => {
      if (response.success && response.data) {
        notify({
          type: 'success',
          message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Page cache settings saved.', 'performance-optimisation'),
          durationMs: 5000
        });
      }
    }).catch(() => notify({
      type: 'error',
      message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Failed to save page cache settings.', 'performance-optimisation'),
      durationMs: 5000
    })).finally(() => setSavingPageCache(false));
  }, [pageCacheEnabled, cacheLife, cacheSettings, notify]);
  const saveLoggedInCacheSettings = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(() => {
    setSavingLoggedInCache(true);
    // Re-read global wppoSettings at call-time to avoid stale closure.
    const currentSettings = (typeof wppoSettings !== 'undefined' ? wppoSettings.settings?.cache_settings : null) ?? cacheSettings ?? {};
    (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_1__.apiCall)('update_settings', {
      tab: 'cache_settings',
      settings: {
        ...currentSettings,
        enableLoggedInCache: loggedInCacheEnabled,
        loggedInCacheRoles
      }
    }).then(response => {
      if (response.success && response.data) {
        notify({
          type: 'success',
          message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Logged-in cache settings saved.', 'performance-optimisation'),
          durationMs: 5000
        });
      }
    }).catch(() => notify({
      type: 'error',
      message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Failed to save logged-in cache settings.', 'performance-optimisation'),
      durationMs: 5000
    })).finally(() => setSavingLoggedInCache(false));
  }, [loggedInCacheEnabled, loggedInCacheRoles, cacheSettings, notify]);
  const saveCdnPurgeSettings = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(() => {
    setSavingCdnPurge(true);
    // Re-read global wppoSettings at call-time to avoid stale closure.
    const currentSettings = (typeof wppoSettings !== 'undefined' ? wppoSettings.settings?.cache_settings : null) ?? cacheSettings ?? {};
    const urls = varnishPurgeUrls.split('\n').map(url => url.trim()).filter(Boolean);
    (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_1__.apiCall)('update_settings', {
      tab: 'cache_settings',
      settings: {
        ...currentSettings,
        cdnPurgeService,
        cloudflareZoneId,
        varnishPurgeUrls: urls
      }
    }).then(response => {
      if (response.success && response.data) {
        notify({
          type: 'success',
          message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('CDN purge settings saved.', 'performance-optimisation'),
          durationMs: 5000
        });
      }
    }).catch(() => notify({
      type: 'error',
      message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Failed to save CDN purge settings.', 'performance-optimisation'),
      durationMs: 5000
    })).finally(() => setSavingCdnPurge(false));
  }, [cdnPurgeService, cloudflareZoneId, varnishPurgeUrls, cacheSettings, notify]);
  const handleLoggedInCacheToggle = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(e => {
    setLoggedInCacheEnabled(e.target.checked);
  }, []);
  const handleRoleCheckbox = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(e => {
    const role = e.target.name;
    const checked = e.target.checked;
    setLoggedInCacheRoles(prev => checked ? [...prev, role] : prev.filter(r => r !== role));
  }, []);
  const totalWebP = (completed.webp || 0) + (pending.webp || 0);
  const totalAvif = (completed.avif || 0) + (pending.avif || 0);
  const totalOptimizedPercent = totalWebP + totalAvif > 0 ? ((completed.webp || 0) + (completed.avif || 0)) / (totalWebP + totalAvif) * 100 : null;
  const isCacheMissing = typeof totalCacheSize === 'string' && /does not exist/i.test(totalCacheSize);
  const cacheSizeValue = !isCacheMissing ? totalCacheSize ?? '—' : '—';
  const cacheSizeUnit = isCacheMissing ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Cache missing', 'performance-optimisation') : '';
  const optimizedFilesCount = (totalJs || 0) + (totalCss || 0);
  let dbBadgeClass = 'wppo-status-badge--good';
  let dbBadgeLabel = (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Healthy', 'performance-optimisation');
  if (dbOverheadCount > 50) {
    dbBadgeClass = 'wppo-status-badge--poor';
    dbBadgeLabel = (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('High', 'performance-optimisation');
  } else if (dbOverheadCount >= 20) {
    dbBadgeClass = 'wppo-status-badge--warning';
    dbBadgeLabel = (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Medium', 'performance-optimisation');
  }

  // LiteSpeed banner data from global wppoSettings (injected by PHP).
  const litespeedInfo = typeof wppoSettings !== 'undefined' ? wppoSettings?.litespeed : null;
  const isLiteSpeed = !!litespeedInfo?.detected;
  const effectiveMode = litespeedInfo?.effective_mode || 'standalone';
  const lscacheActive = !!litespeedInfo?.lscache_active;
  const effectiveLabel = (0,_lib_litespeed__WEBPACK_IMPORTED_MODULE_24__.modeLabel)(effectiveMode);
  const effectiveBadgeClass = effectiveMode === 'litespeed' ? 'wppo-status-badge--warning' : 'wppo-status-badge--good';
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("div", {
    className: "wppo-dashboard-view",
    children: [notice && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_common_NoticeBanner__WEBPACK_IMPORTED_MODULE_9__["default"], {
      type: notice.type,
      message: notice.message,
      onDismiss: dismiss
    }), isLiteSpeed && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("div", {
      className: "wppo-notice wppo-notice--info wppo-litespeed-banner wppo-mb-16",
      role: "alert",
      "aria-live": "polite",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_25__.FontAwesomeIcon, {
        icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_26__.faServer,
        "aria-hidden": "true"
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("span", {
        className: "wppo-litespeed-banner__text",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("strong", {
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('LiteSpeed Detected', 'performance-optimisation')
        }), ' ', lscacheActive ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('LiteSpeed Cache plugin is active.', 'performance-optimisation') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Server is LiteSpeed / OpenLiteSpeed.', 'performance-optimisation')]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("span", {
        className: "wppo-litespeed-banner__badges",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("span", {
          className: `wppo-status-badge ${effectiveBadgeClass}`,
          children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Effective:', 'performance-optimisation'), ' ', effectiveLabel]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("span", {
          className: `wppo-status-badge ${lscacheActive ? 'wppo-status-badge--poor' : 'wppo-status-badge--good'}`,
          children: lscacheActive ? 'LSCache Active' : 'LSCache Inactive'
        })]
      }), lscacheActive && effectiveMode === 'litespeed' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("span", {
        className: "wppo-text-muted wppo-text-small",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('WPPO optimisation is paused in this mode.', 'performance-optimisation')
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_common_FeatureHeader__WEBPACK_IMPORTED_MODULE_5__["default"], {
      title: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.Fragment, {
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("span", {
          className: "wppo-health-dot",
          "aria-hidden": "true",
          children: "\u25CF"
        }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('System Health', 'performance-optimisation')]
      }),
      description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Real-time performance overview and quick optimisation actions.', 'performance-optimisation'),
      status: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.Fragment, {}),
      actions: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_3__["default"], {
        type: "button",
        className: "wppo-button wppo-button--primary",
        onClick: onClearCache,
        isLoading: loading.clear_cache,
        label: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.Fragment, {
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_25__.FontAwesomeIcon, {
            icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_26__.faBroom,
            "aria-hidden": "true",
            style: {
              marginRight: '8px'
            }
          }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Purge All Cache', 'performance-optimisation')]
        }),
        loadingLabel: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Purging…', 'performance-optimisation')
      })
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_WelcomePanel__WEBPACK_IMPORTED_MODULE_22__["default"], {}), isCacheMissing && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("div", {
      className: "wppo-banner wppo-banner--warning",
      role: "alert",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("span", {
        className: "wppo-banner__icon",
        "aria-hidden": "true",
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_25__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_26__.faExclamationTriangle
        })
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("span", {
        className: "wppo-banner__text",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Cache directory not found.', 'performance-optimisation')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("button", {
        type: "button",
        className: "wppo-button wppo-button--primary wppo-button--sm",
        onClick: () => onNavigate('fileOptimization'),
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Fix Now', 'performance-optimisation')
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("div", {
      className: "wppo-stats-grid",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("div", {
        className: "wppo-stat-item wppo-stat-item--cache",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("div", {
          className: "wppo-stat-header",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("span", {
            className: "wppo-stat-label",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Cache Size', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("span", {
            className: "wppo-stat-icon",
            "aria-hidden": "true",
            children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_25__.FontAwesomeIcon, {
              icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_26__.faServer
            })
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("span", {
          className: isCacheMissing ? 'wppo-stat-value wppo-stat-value--muted' : 'wppo-stat-value',
          children: cacheSizeValue
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("span", {
          className: "wppo-stat-unit",
          children:
          // eslint-disable-next-line no-nested-ternary
          isCacheMissing ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.Fragment, {
            children: [cacheSizeUnit, " \u2022", ' ', /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("span", {
              className: "wppo-status-badge wppo-status-badge--poor",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Not cached', 'performance-optimisation')
            })]
          }) : cacheSizeUnit ? cacheSizeUnit : /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("span", {
            className: "wppo-text-muted wppo-text-small",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Ready', 'performance-optimisation')
          })
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("div", {
          className: "wppo-stat-footer",
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("button", {
            type: "button",
            className: "wppo-button wppo-button--secondary wppo-button--sm wppo-stat-link",
            onClick: () => onNavigate('fileOptimization'),
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Manage →', 'performance-optimisation')
          })
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("div", {
        className: "wppo-stat-item wppo-stat-item--files",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("div", {
          className: "wppo-stat-header",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("span", {
            className: "wppo-stat-label",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Optimized Files', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("span", {
            className: "wppo-stat-icon",
            "aria-hidden": "true",
            children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_25__.FontAwesomeIcon, {
              icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_26__.faFileCode
            })
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("span", {
          className: "wppo-stat-value",
          children: optimizedFilesCount
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("span", {
          className: "wppo-stat-unit",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('files', 'performance-optimisation')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("div", {
          className: "wppo-stat-footer",
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("button", {
            type: "button",
            className: "wppo-button wppo-button--secondary wppo-button--sm wppo-stat-link",
            onClick: () => onNavigate('fileOptimization'),
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Configure →', 'performance-optimisation')
          })
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("div", {
        className: "wppo-stat-item wppo-stat-item--db",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("div", {
          className: "wppo-stat-header",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("span", {
            className: "wppo-stat-label",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('DB Overhead', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("span", {
            className: "wppo-stat-icon",
            "aria-hidden": "true",
            children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_25__.FontAwesomeIcon, {
              icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_26__.faDatabase
            })
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("span", {
          className: "wppo-stat-value",
          children: dbOverheadCount
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("span", {
          className: "wppo-stat-unit",
          children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('items', 'performance-optimisation'), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("span", {
            className: `wppo-status-badge ${dbBadgeClass}`,
            children: dbBadgeLabel
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("div", {
          className: "wppo-stat-footer",
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("button", {
            type: "button",
            className: "wppo-button wppo-button--secondary wppo-button--sm wppo-stat-link",
            onClick: () => onNavigate('databaseCleanup'),
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Optimize →', 'performance-optimisation')
          })
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("div", {
        className: "wppo-stat-item wppo-stat-item--images",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("div", {
          className: "wppo-stat-header",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("span", {
            className: "wppo-stat-label",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Images Optimized', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("span", {
            className: "wppo-stat-icon",
            "aria-hidden": "true",
            children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_25__.FontAwesomeIcon, {
              icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_26__.faImages
            })
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("span", {
          className: totalOptimizedPercent === null ? 'wppo-stat-value wppo-stat-value--muted' : 'wppo-stat-value',
          children: totalOptimizedPercent !== null ? `${totalOptimizedPercent.toFixed(0)}%` : '—'
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("span", {
          className: "wppo-stat-unit",
          children: totalOptimizedPercent !== null ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('optimized', 'performance-optimisation') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('No images', 'performance-optimisation')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("div", {
          className: "wppo-stat-footer",
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("button", {
            type: "button",
            className: "wppo-button wppo-button--secondary wppo-button--sm wppo-stat-link",
            onClick: () => onNavigate('imageOptimization'),
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('View →', 'performance-optimisation')
          })
        })]
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_6__["default"], {
      title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Page Cache', 'performance-optimisation'),
      icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("i", {
        className: "fas fa-bolt"
      }),
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_7__["default"], {
        label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Enable Page Cache', 'performance-optimisation'),
        description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Generate static HTML copies of your pages and serve them to visitors without running WordPress. Recommended for faster TTFB on non-logged-in traffic.', 'performance-optimisation'),
        name: "enableCache",
        checked: pageCacheEnabled,
        onChange: e => setPageCacheEnabled(e.target.checked)
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("div", {
        className: "wppo-field",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("label", {
          className: "wppo-field-label",
          htmlFor: "wppoCacheLife",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Cache Lifespan', 'performance-optimisation')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("select", {
          className: "wppo-select",
          id: "wppoCacheLife",
          name: "cacheLife",
          value: cacheLife,
          onChange: e => setCacheLife(Number(e.target.value)),
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("option", {
            value: 0,
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Never expire', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("option", {
            value: 1,
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('1 hour', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("option", {
            value: 6,
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('6 hours', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("option", {
            value: 12,
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('12 hours', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("option", {
            value: 24,
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('24 hours', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("option", {
            value: 48,
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('48 hours', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("option", {
            value: 168,
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('1 week', 'performance-optimisation')
          })]
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("div", {
        className: "wppo-feature-card__footer",
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_3__["default"], {
          className: "wppo-button wppo-button--primary",
          onClick: savePageCacheSettings,
          isLoading: savingPageCache,
          label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Save Page Cache Settings', 'performance-optimisation'),
          loadingLabel: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Saving…', 'performance-optimisation')
        })
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_6__["default"], {
      title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('CDN Cache Purge', 'performance-optimisation'),
      icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("i", {
        className: "fas fa-globe"
      }),
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("div", {
        className: "wppo-field",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("label", {
          className: "wppo-field-label",
          htmlFor: "cdnPurgeService",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('CDN Purge Service', 'performance-optimisation')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("select", {
          className: "wppo-select",
          id: "cdnPurgeService",
          name: "cdnPurgeService",
          value: cdnPurgeService,
          onChange: e => setCdnPurgeService(e.target.value),
          "aria-describedby": "wppo-cdnPurgeService-desc",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("option", {
            value: "none",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('None', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("option", {
            value: "cloudflare",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Cloudflare', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("option", {
            value: "varnish",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Varnish', 'performance-optimisation')
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("p", {
          id: "wppo-cdnPurgeService-desc",
          className: "wppo-text-muted wppo-text-small",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Purge the edge cache whenever the plugin cache is cleared.', 'performance-optimisation')
        })]
      }), cdnPurgeService === 'cloudflare' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("div", {
        className: "wppo-field",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("label", {
          className: "wppo-field-label",
          htmlFor: "cloudflareZoneId",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Cloudflare Zone ID', 'performance-optimisation')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("input", {
          className: "wppo-input",
          id: "cloudflareZoneId",
          name: "cloudflareZoneId",
          type: "text",
          value: cloudflareZoneId,
          onChange: e => setCloudflareZoneId(e.target.value),
          "aria-describedby": "wppo-cloudflareZoneId-desc"
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("p", {
          id: "wppo-cloudflareZoneId-desc",
          className: "wppo-text-muted wppo-text-small",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Define WPPO_CLOUDFLARE_API_TOKEN in wp-config.php with an API token that has Zone > Cache Purge permission. The token is never stored in the database.', 'performance-optimisation')
        })]
      }), cdnPurgeService === 'varnish' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("div", {
        className: "wppo-field",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("label", {
          className: "wppo-field-label",
          htmlFor: "varnishPurgeUrls",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Varnish Purge Endpoints', 'performance-optimisation')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("textarea", {
          className: "wppo-textarea",
          id: "varnishPurgeUrls",
          name: "varnishPurgeUrls",
          rows: 3,
          value: varnishPurgeUrls,
          onChange: e => setVarnishPurgeUrls(e.target.value),
          "aria-describedby": "wppo-varnishPurgeUrls-desc",
          placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('http://127.0.0.1:8081/purge', 'performance-optimisation')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("p", {
          id: "wppo-varnishPurgeUrls-desc",
          className: "wppo-text-muted wppo-text-small",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('One URL per line. Each receives a PURGE request on cache clear.', 'performance-optimisation')
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("div", {
        className: "wppo-feature-card__footer",
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_3__["default"], {
          className: "wppo-button wppo-button--primary",
          onClick: saveCdnPurgeSettings,
          isLoading: savingCdnPurge,
          label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Save CDN Purge', 'performance-optimisation'),
          loadingLabel: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Saving…', 'performance-optimisation')
        })
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_6__["default"], {
      title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Cache for Logged-in Users', 'performance-optimisation'),
      icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("i", {
        className: "fas fa-user-check"
      }),
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_7__["default"], {
        label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Enable', 'performance-optimisation'),
        description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Serve cached pages to logged-in users based on their role(s). The admin bar and user-specific content are preserved per role group.', 'performance-optimisation'),
        name: "enableLoggedInCache",
        checked: loggedInCacheEnabled,
        onChange: handleLoggedInCacheToggle
      }), loggedInCacheEnabled && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("div", {
        className: "wppo-logged-in-cache-roles",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("p", {
          className: "wppo-text-muted",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Select which user roles should receive cached pages:', 'performance-optimisation')
        }), Object.entries(userRoles).map(([slug, name]) => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_common_CheckboxOption__WEBPACK_IMPORTED_MODULE_8__["default"], {
          label: name,
          name: slug,
          checked: loggedInCacheRoles.includes(slug),
          onChange: handleRoleCheckbox
        }, slug)), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("p", {
          className: "wppo-text-muted wppo-mt-10",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('When no roles are selected, caching applies to all logged-in users.', 'performance-optimisation')
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)("div", {
        className: "wppo-feature-card__footer",
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_3__["default"], {
          className: "wppo-button wppo-button--primary",
          onClick: saveLoggedInCacheSettings,
          isLoading: savingLoggedInCache,
          label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Save Settings', 'performance-optimisation'),
          loadingLabel: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Saving…', 'performance-optimisation')
        })
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("div", {
      className: "wppo-stacked-cards",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_PerformanceAudit__WEBPACK_IMPORTED_MODULE_10__["default"], {
        onSuggestionsReady: setTelemetrySuggestions,
        onUrlChange: setAuditUrl
      }), allSuggestions.length > 0 && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_SuggestionsPanel__WEBPACK_IMPORTED_MODULE_14__["default"], {
        suggestions: allSuggestions,
        onNavigate: onNavigate
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_PageSpeedPanel__WEBPACK_IMPORTED_MODULE_11__["default"], {
        url: auditUrl,
        onSuggestionsReady: setPagespeedSuggestions
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_WebVitalsTrends__WEBPACK_IMPORTED_MODULE_12__["default"], {
        url: auditUrl
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_WebVitalsRum__WEBPACK_IMPORTED_MODULE_13__["default"], {}), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_AutoloadedOptions__WEBPACK_IMPORTED_MODULE_16__["default"], {}), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_LlmsPanel__WEBPACK_IMPORTED_MODULE_17__["default"], {}), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_AiPanel__WEBPACK_IMPORTED_MODULE_18__["default"], {}), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_EdgeCachePanel__WEBPACK_IMPORTED_MODULE_19__["default"], {}), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_SystemInfo__WEBPACK_IMPORTED_MODULE_15__["default"], {})]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsxs)("div", {
      className: "wppo-stacked-cards wppo-mt-20",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_ImageOptimizationCard__WEBPACK_IMPORTED_MODULE_20__["default"], {
        completed: completed,
        pending: pending,
        failed: failed,
        bgProcessing: bgProcessing,
        bgJobsQueued: bgJobsQueued,
        loading: loading,
        savings: imgSavings,
        pendingPathsCount: (pending.webp || 0) + (pending.avif || 0),
        onOptimize: optimizeImages,
        onRemove: () => setConfirmRemove(true)
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_RecentActivityCard__WEBPACK_IMPORTED_MODULE_21__["default"], {
        activities: activities,
        onNavigate: onNavigate
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_27__.jsx)(_common_ConfirmDialog__WEBPACK_IMPORTED_MODULE_4__["default"], {
      isOpen: confirmRemove,
      onConfirm: () => {
        setConfirmRemove(false);
        removeImages();
      },
      onCancel: () => setConfirmRemove(false),
      title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Remove Optimized Images', 'performance-optimisation'),
      message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('This will delete all optimized WebP and AVIF copies. Original images will not be affected.', 'performance-optimisation'),
      confirmLabel: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_23__.__)('Delete', 'performance-optimisation'),
      variant: "danger"
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (Dashboard);

/***/ },

/***/ "./src/components/EdgeCachePanel.js"
/*!******************************************!*\
  !*** ./src/components/EdgeCachePanel.js ***!
  \******************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _lib_apiRequest__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../lib/apiRequest */ "./src/lib/apiRequest.js");
/* harmony import */ var _lib_useNotice__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../lib/useNotice */ "./src/lib/useNotice.js");
/* harmony import */ var _common_FeatureCard__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ./common/FeatureCard */ "./src/components/common/FeatureCard.js");
/* harmony import */ var _common_SwitchField__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./common/SwitchField */ "./src/components/common/SwitchField.js");
/* harmony import */ var _common_NoticeBanner__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! ./common/NoticeBanner */ "./src/components/common/NoticeBanner.js");
/* harmony import */ var _common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_7__ = __webpack_require__(/*! ./common/LoadingSubmitButton */ "./src/components/common/LoadingSubmitButton.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__);









/**
 * Edge Cache panel (N2).
 *
 * Host-agnostic Cloudflare Workers / Bunny Edge adapter.
 * - Generates wrangler.toml + cloudflare-worker.js semantics
 * - Purge via Edge_Purger alongside CDN_Purger on wppo_after_cache_clear
 * - Stale-while-revalidate <30ms global TTFB
 *
 * @since NEXT
 */

const EdgeCachePanel = () => {
  const initial = typeof wppoSettings !== 'undefined' ? wppoSettings?.settings?.edge_cache || {} : {};
  const [enabled, setEnabled] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(!!initial.enabled);
  const [provider, setProvider] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(initial.provider || 'cloudflare');
  const [ttl, setTtl] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(String(initial.ttl ?? 300));
  const [swr, setSwr] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(String(initial.staleWhileRevalidate ?? 86400));
  const [cfZone, setCfZone] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(initial.cloudflareZoneId || '');
  const [bunnyZone, setBunnyZone] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(initial.bunnyPullZoneId || '');
  const [saving, setSaving] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const {
    notice,
    notify,
    dismiss
  } = (0,_lib_useNotice__WEBPACK_IMPORTED_MODULE_3__["default"])();
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    const s = typeof wppoSettings !== 'undefined' ? wppoSettings?.settings?.edge_cache || {} : {};
    setEnabled(!!s.enabled);
    setProvider(s.provider || 'cloudflare');
    setTtl(String(s.ttl ?? 300));
    setSwr(String(s.staleWhileRevalidate ?? 86400));
    setCfZone(s.cloudflareZoneId || '');
    setBunnyZone(s.bunnyPullZoneId || '');
  }, []);
  const handleSave = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(async () => {
    setSaving(true);
    dismiss();
    try {
      const response = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_2__.apiCall)('update_settings', {
        tab: 'edge_cache',
        settings: {
          enabled,
          provider,
          ttl: parseInt(ttl, 10) || 300,
          staleWhileRevalidate: parseInt(swr, 10) || 86400,
          cloudflareZoneId: cfZone,
          bunnyPullZoneId: bunnyZone
        }
      });
      if (response.success) {
        if (typeof wppoSettings !== 'undefined' && wppoSettings.settings) {
          wppoSettings.settings.edge_cache = {
            enabled,
            provider,
            ttl: parseInt(ttl, 10) || 300,
            staleWhileRevalidate: parseInt(swr, 10) || 86400,
            cloudflareZoneId: cfZone,
            bunnyPullZoneId: bunnyZone
          };
        }
        notify({
          type: 'success',
          message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Edge cache settings saved.', 'performance-optimisation'),
          durationMs: 3000
        });
      } else {
        notify({
          type: 'error',
          message: response.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to save edge cache settings.', 'performance-optimisation')
        });
      }
    } catch {
      notify({
        type: 'error',
        message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to save edge cache settings.', 'performance-optimisation')
      });
    } finally {
      setSaving(false);
    }
  }, [enabled, provider, ttl, swr, cfZone, bunnyZone, notify, dismiss]);
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsxs)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_4__["default"], {
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Edge HTML Cache', 'performance-optimisation'),
    icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("i", {
      className: "fas fa-globe"
    }),
    children: [notice && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)(_common_NoticeBanner__WEBPACK_IMPORTED_MODULE_6__["default"], {
      type: notice.type,
      message: notice.message,
      onDismiss: dismiss
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_5__["default"], {
      label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Enable Edge Cache', 'performance-optimisation'),
      description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Deploy cache/wppo/{domain}/{path}/index.html semantics to Cloudflare Workers / Bunny Edge via stale-while-revalidate. TTFB <30ms global (edge) vs LS-local 90ms. Disabled by default — no behaviour change until enabled.', 'performance-optimisation'),
      name: "edgeCacheEnabled",
      checked: enabled,
      onChange: e => setEnabled(e.target.checked)
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("p", {
      className: "wppo-text-muted wppo-text-small",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Gated by wppo_edge_cache_enabled filter. Purge via Edge_Purger::purge_all on wppo_after_cache_clear (transient lock, multisite-safe).', 'performance-optimisation')
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsxs)("div", {
      className: "wppo-field",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("label", {
        className: "wppo-field-label",
        htmlFor: "wppoEdgeProvider",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Provider', 'performance-optimisation')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsxs)("select", {
        className: "wppo-select",
        id: "wppoEdgeProvider",
        value: provider,
        onChange: e => setProvider(e.target.value),
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("option", {
          value: "cloudflare",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Cloudflare Workers', 'performance-optimisation')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("option", {
          value: "bunny",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Bunny Edge', 'performance-optimisation')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("option", {
          value: "both",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Both', 'performance-optimisation')
        })]
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsxs)("div", {
      className: "wppo-field",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("label", {
        className: "wppo-field-label",
        htmlFor: "wppoEdgeTtl",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Cache TTL (seconds)', 'performance-optimisation')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("input", {
        className: "wppo-input",
        id: "wppoEdgeTtl",
        type: "number",
        min: "60",
        value: ttl,
        onChange: e => setTtl(e.target.value)
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsxs)("div", {
      className: "wppo-field",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("label", {
        className: "wppo-field-label",
        htmlFor: "wppoEdgeSwr",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Stale-While-Revalidate (seconds)', 'performance-optimisation')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("input", {
        className: "wppo-input",
        id: "wppoEdgeSwr",
        type: "number",
        min: "0",
        value: swr,
        onChange: e => setSwr(e.target.value)
      })]
    }), (provider === 'cloudflare' || provider === 'both') && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsxs)("div", {
      className: "wppo-field",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("label", {
        className: "wppo-field-label",
        htmlFor: "wppoEdgeCfZone",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Cloudflare Zone ID', 'performance-optimisation')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("input", {
        className: "wppo-input",
        id: "wppoEdgeCfZone",
        type: "text",
        value: cfZone,
        onChange: e => setCfZone(e.target.value),
        placeholder: "abc123"
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("p", {
        className: "wppo-text-muted wppo-text-small",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Define WPPO_CLOUDFLARE_API_TOKEN in wp-config.php with Zone > Cache Purge permission.', 'performance-optimisation')
      })]
    }), (provider === 'bunny' || provider === 'both') && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsxs)("div", {
      className: "wppo-field",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("label", {
        className: "wppo-field-label",
        htmlFor: "wppoEdgeBunnyZone",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Bunny Pull Zone ID', 'performance-optimisation')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("input", {
        className: "wppo-input",
        id: "wppoEdgeBunnyZone",
        type: "text",
        value: bunnyZone,
        onChange: e => setBunnyZone(e.target.value),
        placeholder: "12345"
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("p", {
        className: "wppo-text-muted wppo-text-small",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Define WPPO_BUNNY_API_KEY in wp-config.php with Pull Zone > Purge permission.', 'performance-optimisation')
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("div", {
      className: "wppo-feature-card__footer",
      children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_7__["default"], {
        className: "wppo-button wppo-button--primary",
        onClick: handleSave,
        isLoading: saving,
        label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Save Edge Cache', 'performance-optimisation'),
        loadingLabel: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Saving…', 'performance-optimisation')
      })
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("p", {
      className: "wppo-text-muted wppo-text-small wppo-mt-12",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Worker: templates/cloudflare-worker.js • Wrangler: generated via Edge_Cache::get_wrangler_toml() • Bunny: templates/bunny-edge.js', 'performance-optimisation')
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (EdgeCachePanel);

/***/ },

/***/ "./src/components/ImageOptimizationCard.js"
/*!*************************************************!*\
  !*** ./src/components/ImageOptimizationCard.js ***!
  \*************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @fortawesome/react-fontawesome */ "./node_modules/@fortawesome/react-fontawesome/index.es.js");
/* harmony import */ var _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @fortawesome/free-solid-svg-icons */ "./node_modules/@fortawesome/free-solid-svg-icons/index.mjs");
/* harmony import */ var _common_FeatureCard__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./common/FeatureCard */ "./src/components/common/FeatureCard.js");
/* harmony import */ var _common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./common/LoadingSubmitButton */ "./src/components/common/LoadingSubmitButton.js");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_4___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__);
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__);
/**
 * ImageOptimizationCard component.
 *
 * @since 1.5.0
 */







/**
 * Format a byte count as a human-readable size string.
 *
 * @param {number} bytes Byte count.
 * @return {string} Formatted size (e.g. "1.5 MB").
 */

const formatBytes = bytes => {
  if (!bytes || bytes <= 0) {
    return '0 B';
  }
  const units = ['B', 'KB', 'MB', 'GB'];
  const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
  return `${(bytes / 1024 ** index).toFixed(1)} ${units[index]}`;
};
const ImageOptimizationCard = ({
  completed = {},
  pending = {},
  failed = {},
  bgProcessing = false,
  bgJobsQueued = 0,
  loading = {},
  pendingPathsCount = 0,
  savings = null,
  onOptimize,
  onRemove
}) => {
  const totalWebP = (completed.webp || 0) + (pending.webp || 0) + (failed.webp || 0);
  const totalAvif = (completed.avif || 0) + (pending.avif || 0) + (failed.avif || 0);
  const webpPercent = totalWebP > 0 ? (completed.webp || 0) / totalWebP * 100 : 0;
  const avifPercent = totalAvif > 0 ? (completed.avif || 0) / totalAvif * 100 : 0;
  const failedWebP = failed.webp || 0;
  const failedAvif = failed.avif || 0;
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_2__["default"], {
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('Image Optimisation', 'performance-optimisation'),
    icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_0__.FontAwesomeIcon, {
      icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_1__.faImages
    }),
    footer: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.Fragment, {
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_3__["default"], {
        className: "wppo-button wppo-button--primary",
        onClick: onOptimize,
        isLoading: loading.optimize_images,
        disabled: bgProcessing || pendingPathsCount === 0,
        label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('Optimize All', 'performance-optimisation'),
        loadingLabel: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('Optimizing…', 'performance-optimisation')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_3__["default"], {
        className: "wppo-button wppo-button--danger",
        onClick: onRemove,
        isLoading: loading.remove_images,
        disabled: !completed.webp && !completed.avif,
        label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('Remove Optimized', 'performance-optimisation'),
        loadingLabel: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('Removing…', 'performance-optimisation')
      })]
    }),
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("div", {
      className: "wppo-progress-grid",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("div", {
        className: "wppo-progress-section",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("div", {
          className: "wppo-progress-header",
          id: "wppo-webp-progress-label",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("span", {
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('WebP Conversion Progress', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("span", {
            children: [completed.webp || 0, " / ", totalWebP]
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("div", {
          className: "wppo-progress-bar",
          role: "progressbar",
          "aria-labelledby": "wppo-webp-progress-label",
          "aria-valuemin": "0",
          "aria-valuemax": "100",
          "aria-valuenow": Math.round(webpPercent),
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("div", {
            className: "wppo-progress-bar__fill",
            style: {
              width: `${webpPercent}%`
            }
          })
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("div", {
        className: "wppo-progress-section",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("div", {
          className: "wppo-progress-header",
          id: "wppo-avif-progress-label",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("span", {
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('AVIF Conversion Progress', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("span", {
            children: [completed.avif || 0, " / ", totalAvif]
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("div", {
          className: "wppo-progress-bar",
          role: "progressbar",
          "aria-labelledby": "wppo-avif-progress-label",
          "aria-valuemin": "0",
          "aria-valuemax": "100",
          "aria-valuenow": Math.round(avifPercent),
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("div", {
            className: "wppo-progress-bar__fill",
            style: {
              width: `${avifPercent}%`
            }
          })
        })]
      })]
    }), (failedWebP > 0 || failedAvif > 0) && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("div", {
      className: "wppo-text-muted wppo-text-small wppo-mt-10",
      "aria-live": "polite",
      children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('Failed conversions:', 'performance-optimisation'), ' ', "WebP ", failedWebP, ", AVIF ", failedAvif, ' ', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('(included in total)', 'performance-optimisation')]
    }), savings && savings.original_bytes > 0 && savings.images_counted > 0 && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("div", {
      className: "wppo-image-savings wppo-mt-16",
      "aria-live": "polite",
      children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("span", {
        children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('Original', 'performance-optimisation'), ' ', formatBytes(savings.original_bytes), ' ', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('→ Optimised', 'performance-optimisation'), ' ', formatBytes(savings.converted_bytes), " (", Math.max(0, Math.round(savings.saved_bytes / savings.original_bytes * 100)), "% ", (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('smaller', 'performance-optimisation'), " \xB7", ' ', savings.images_counted, ' ', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('images', 'performance-optimisation'), ")"]
      })
    }), (bgProcessing || bgJobsQueued > 0) && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("div", {
      className: "wppo-notice wppo-notice--info wppo-mt-32",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_0__.FontAwesomeIcon, {
        icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_1__.faSpinner,
        spin: true
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("span", {
        children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('Currently processing background optimisation jobs', 'performance-optimisation'), ' ', "( ", bgJobsQueued, ' ', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('queued', 'performance-optimisation'), ")"]
      })]
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (ImageOptimizationCard);

/***/ },

/***/ "./src/components/LlmsPanel.js"
/*!*************************************!*\
  !*** ./src/components/LlmsPanel.js ***!
  \*************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _lib_apiRequest__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../lib/apiRequest */ "./src/lib/apiRequest.js");
/* harmony import */ var _lib_useNotice__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../lib/useNotice */ "./src/lib/useNotice.js");
/* harmony import */ var _common_FeatureCard__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ./common/FeatureCard */ "./src/components/common/FeatureCard.js");
/* harmony import */ var _common_SwitchField__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./common/SwitchField */ "./src/components/common/SwitchField.js");
/* harmony import */ var _common_NoticeBanner__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! ./common/NoticeBanner */ "./src/components/common/NoticeBanner.js");
/* harmony import */ var _common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_7__ = __webpack_require__(/*! ./common/LoadingSubmitButton */ "./src/components/common/LoadingSubmitButton.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__);









/**
 * LLMs.txt panel for Dashboard (N8).
 *
 * @since NEXT
 */

const LlmsPanel = () => {
  const initial = typeof wppoSettings !== 'undefined' ? wppoSettings?.settings?.llms_txt || {} : {};
  const [enabled, setEnabled] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(!!initial.enabled);
  const [source, setSource] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(initial.source || 'both');
  const [saving, setSaving] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const {
    notice,
    notify,
    dismiss
  } = (0,_lib_useNotice__WEBPACK_IMPORTED_MODULE_3__["default"])();
  const homeUrl = typeof wppoSettings !== 'undefined' ? wppoSettings?.homeUrl || '' : '';
  const llmsUrl = homeUrl ? `${homeUrl.replace(/\/$/, '')}/llms.txt` : '/llms.txt';
  const handleSave = async () => {
    setSaving(true);
    try {
      const response = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_2__.apiCall)('update_settings', {
        tab: 'llms_txt',
        settings: {
          enabled,
          source
        }
      });
      if (response.success) {
        // Mutate global for next mount.
        if (typeof wppoSettings !== 'undefined' && wppoSettings.settings) {
          wppoSettings.settings.llms_txt = {
            enabled,
            source
          };
        }
        notify({
          type: 'success',
          message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('LLMs.txt settings saved.', 'performance-optimisation'),
          durationMs: 5000
        });
      } else {
        notify({
          type: 'error',
          message: response.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to save LLMs.txt settings.', 'performance-optimisation')
        });
      }
    } catch {
      notify({
        type: 'error',
        message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to save LLMs.txt settings.', 'performance-optimisation')
      });
    } finally {
      setSaving(false);
    }
  };
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsxs)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_4__["default"], {
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('LLMs.txt', 'performance-optimisation'),
    icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("i", {
      className: "fas fa-robot"
    }),
    children: [notice && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)(_common_NoticeBanner__WEBPACK_IMPORTED_MODULE_6__["default"], {
      type: notice.type,
      message: notice.message,
      onDismiss: dismiss
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_5__["default"], {
      label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Enable LLMs.txt', 'performance-optimisation'),
      description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Generate /llms.txt and /llms-full.txt for AI crawlers from top URLs (trends + sitemap). Opt-in, local file only.', 'performance-optimisation'),
      name: "llmsEnabled",
      checked: enabled,
      onChange: e => setEnabled(e.target.checked)
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsxs)("div", {
      className: "wppo-field",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("label", {
        className: "wppo-field-label",
        htmlFor: "wppoLlmsSource",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Source', 'performance-optimisation')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsxs)("select", {
        className: "wppo-select",
        id: "wppoLlmsSource",
        value: source,
        onChange: e => setSource(e.target.value),
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("option", {
          value: "both",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Both (Trends + Sitemap)', 'performance-optimisation')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("option", {
          value: "trends",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Trends only', 'performance-optimisation')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("option", {
          value: "sitemap",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Sitemap only', 'performance-optimisation')
        })]
      })]
    }), enabled && homeUrl && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsxs)("p", {
      className: "wppo-text-muted wppo-text-small",
      children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('File will be available at:', 'performance-optimisation'), ' ', /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("a", {
        href: llmsUrl,
        target: "_blank",
        rel: "noreferrer",
        children: llmsUrl
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)("div", {
      className: "wppo-feature-card__footer",
      children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_8__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_7__["default"], {
        className: "wppo-button wppo-button--primary",
        onClick: handleSave,
        isLoading: saving,
        label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Save LLMs.txt Settings', 'performance-optimisation'),
        loadingLabel: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Saving…', 'performance-optimisation')
      })
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (LlmsPanel);

/***/ },

/***/ "./src/components/PageSpeedPanel.js"
/*!******************************************!*\
  !*** ./src/components/PageSpeedPanel.js ***!
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
/* harmony import */ var _lib_apiRequest__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../lib/apiRequest */ "./src/lib/apiRequest.js");
/* harmony import */ var _lib_useNotice__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ../lib/useNotice */ "./src/lib/useNotice.js");
/* harmony import */ var _common_FeatureCard__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./common/FeatureCard */ "./src/components/common/FeatureCard.js");
/* harmony import */ var _common_StatusBadge__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! ./common/StatusBadge */ "./src/components/common/StatusBadge.js");
/* harmony import */ var _common_NoticeBanner__WEBPACK_IMPORTED_MODULE_7__ = __webpack_require__(/*! ./common/NoticeBanner */ "./src/components/common/NoticeBanner.js");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_8___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__);
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__);
/**
 * PageSpeedPanel component.
 *
 * Provides a "Run PageSpeed Scan" button that queues a background
 * Google PageSpeed Insights scan via POST /pagespeed_scan, then polls
 * GET /pagespeed_results until the result is ready.
 *
 * Renders Lighthouse category scores, Core Web Vitals, and passes
 * the PageSpeed suggestions up to the parent via onSuggestionsReady.
 *
 * Disabled when pagespeedApiKeyConfigured is false.
 *
 * @since 1.6.0
 */











// apiKeyConfigured is now derived inside the component for reactivity.

/**
 * Polling interval in milliseconds.
 * PageSpeed API typically takes 15–60 seconds.
 */

const POLL_INTERVAL_MS = 5000;

/**
 * Maximum number of poll attempts before giving up (~5 minutes).
 */
const MAX_POLL_ATTEMPTS = 60;

/**
 * Score colour based on Lighthouse thresholds.
 *
 * @param {number} score 0–100
 * @return {string} CSS class suffix.
 */
const scoreStatus = score => {
  if (score >= 90) {
    return 'good';
  }
  if (score >= 50) {
    return 'needs_improvement';
  }
  return 'poor';
};

/**
 * A single Lighthouse category score gauge.
 *
 * @param {Object} props
 * @param {string} props.label Category label.
 * @param {number} props.score 0–100 integer.
 */
const ScoreGauge = ({
  label,
  score
}) => {
  const status = scoreStatus(score);
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsxs)("div", {
    className: `wppo-score-gauge wppo-score-gauge--${status}`,
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)("div", {
      className: "wppo-score-gauge__circle",
      children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)("span", {
        className: "wppo-score-gauge__value",
        children: score
      })
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)("span", {
      className: "wppo-score-gauge__label",
      children: label
    })]
  });
};

/**
 * A single Core Web Vital row.
 *
 * @param {Object} props
 * @param {string} props.label        Metric label.
 * @param {string} props.displayValue Formatted value from Lighthouse.
 * @param {number} props.score        0.0–1.0 Lighthouse score.
 */
const VitalRow = ({
  label,
  displayValue,
  score
}) => {
  let status = null;
  if (score !== null) {
    if (score >= 0.9) {
      status = 'good';
    } else if (score >= 0.5) {
      status = 'needs_improvement';
    } else {
      status = 'poor';
    }
  }
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsxs)("tr", {
    className: "wppo-vitals-table__row",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)("td", {
      className: "wppo-vitals-table__label",
      children: label
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)("td", {
      className: "wppo-vitals-table__value",
      children: displayValue ?? '—'
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)("td", {
      className: "wppo-vitals-table__status",
      children: status && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)(_common_StatusBadge__WEBPACK_IMPORTED_MODULE_6__["default"], {
        status: status
      })
    })]
  });
};
const PageSpeedPanel = ({
  url,
  onSuggestionsReady
}) => {
  const [scanning, setScanning] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [pending, setPending] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [result, setResult] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const {
    notice,
    notify,
    dismiss
  } = (0,_lib_useNotice__WEBPACK_IMPORTED_MODULE_4__["default"])();
  const [strategy, setStrategy] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)('mobile');
  const pollRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(null);
  const pollCountRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(0);
  const submittingRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(false);
  const stopPolling = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(() => {
    if (pollRef.current) {
      clearTimeout(pollRef.current);
      pollRef.current = null;
    }
    pollCountRef.current = 0;
  }, []);
  const isMounted = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(true);

  // Component lifecycle and polling cleanup.
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    isMounted.current = true;
    return () => {
      isMounted.current = false;
      stopPolling();
    };
  }, [stopPolling]);
  const apiKeyConfigured = typeof wppoSettings !== 'undefined' ? wppoSettings.performance_audit?.pagespeedApiKeyConfigured ?? false : false;
  const pollForResults = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)((scanUrl, scanStrategy) => {
    const poll = async () => {
      pollCountRef.current += 1;
      if (pollCountRef.current > MAX_POLL_ATTEMPTS) {
        stopPolling();
        setPending(false);
        setScanning(false);
        notify({
          type: 'error',
          message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('PageSpeed scan timed out. Please try again.', 'performance-optimisation')
        });
        return;
      }
      try {
        const response = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_3__.getPagespeedResults)(scanUrl, scanStrategy);
        if (!response.success) {
          stopPolling();
          setPending(false);
          setScanning(false);
          notify({
            type: 'error',
            message: response.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('PageSpeed scan failed. Please try again.', 'performance-optimisation')
          });
          return;
        }
        if (response.data?.status === 'not_ready') {
          if (isMounted.current) {
            pollRef.current = setTimeout(poll, POLL_INTERVAL_MS);
          }
          return;
        }
        if (isMounted.current) {
          stopPolling();
          setPending(false);
          setScanning(false);
          setResult(response.data);
          if (onSuggestionsReady && response.data?.suggestions) {
            onSuggestionsReady(response.data.suggestions);
          }
        }
      } catch (err) {
        stopPolling();
        setPending(false);
        setScanning(false);
        notify({
          type: 'error',
          message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('PageSpeed scan failed.', 'performance-optimisation')
        });
        console.error('PageSpeed poll error:', err);
      }
    };
    pollRef.current = setTimeout(poll, POLL_INTERVAL_MS);
  }, [stopPolling, onSuggestionsReady, notify]);
  const handleScan = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(async () => {
    if (!url || scanning || pending || submittingRef.current) {
      return;
    }
    submittingRef.current = true;
    stopPolling();
    setScanning(true);
    setPending(false);
    setResult(null);
    dismiss();
    try {
      const response = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_3__.queuePagespeedScan)(url, strategy);
      if (!response.success) {
        setScanning(false);
        submittingRef.current = false;
        notify({
          type: 'error',
          message: response.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('PageSpeed scan failed. Please try again.', 'performance-optimisation')
        });
        return;
      }

      // Job queued — start polling.
      setPending(true);
      submittingRef.current = false;
      pollForResults(url, strategy);
    } catch (err) {
      setScanning(false);
      submittingRef.current = false;
      notify({
        type: 'error',
        message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('PageSpeed scan failed.', 'performance-optimisation')
      });
      console.error('PageSpeed scan error:', err);
    }
  }, [url, strategy, stopPolling, pollForResults, scanning, pending, notify, dismiss]);
  const vitalsLabels = {
    fcp: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('First Contentful Paint', 'performance-optimisation'),
    lcp: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('Largest Contentful Paint', 'performance-optimisation'),
    tbt: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('Total Blocking Time', 'performance-optimisation'),
    cls: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('Cumulative Layout Shift', 'performance-optimisation'),
    speed_index: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('Speed Index', 'performance-optimisation'),
    tti: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('Time to Interactive', 'performance-optimisation')
  };
  const categoryLabels = {
    performance: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('Performance', 'performance-optimisation'),
    accessibility: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('Accessibility', 'performance-optimisation'),
    best_practices: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('Best Practices', 'performance-optimisation'),
    seo: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('SEO', 'performance-optimisation')
  };
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsxs)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_5__["default"], {
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('PageSpeed Insights', 'performance-optimisation'),
    children: [!apiKeyConfigured && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsxs)("div", {
      className: "wppo-notice wppo-notice--warning",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
        icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faExclamationCircle,
        className: "wppo-mr-8"
      }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('PageSpeed API key is not configured. Add it in Settings.', 'performance-optimisation')]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsxs)("div", {
      className: "wppo-pagespeed-controls",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsxs)("div", {
        className: "wppo-pagespeed-strategy",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsxs)("button", {
          type: "button",
          className: `wppo-strategy-btn ${strategy === 'mobile' ? 'wppo-strategy-btn--active' : ''}`,
          onClick: () => setStrategy('mobile'),
          disabled: scanning || pending,
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
            icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faMobileAlt
          }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('Mobile', 'performance-optimisation')]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsxs)("button", {
          type: "button",
          className: `wppo-strategy-btn ${strategy === 'desktop' ? 'wppo-strategy-btn--active' : ''}`,
          onClick: () => setStrategy('desktop'),
          disabled: scanning || pending,
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
            icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faDesktop
          }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('Desktop', 'performance-optimisation')]
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)("button", {
        type: "button",
        className: "wppo-button wppo-button--primary",
        onClick: handleScan,
        disabled: !apiKeyConfigured || scanning || pending,
        children: scanning || pending ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.Fragment, {
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
            icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faSpinner,
            spin: true,
            className: "wppo-mr-8"
          }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('Scanning…', 'performance-optimisation')]
        }) : /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.Fragment, {
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
            icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faTachometerAlt,
            className: "wppo-mr-8"
          }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('Run PageSpeed Scan', 'performance-optimisation')]
        })
      })]
    }), pending && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsxs)("div", {
      className: "wppo-notice wppo-notice--info",
      role: "alert",
      "aria-live": "polite",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
        icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faSpinner,
        spin: true,
        className: "wppo-mr-8"
      }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('PageSpeed scan is running in the background. Results will appear shortly.', 'performance-optimisation')]
    }), notice && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)(_common_NoticeBanner__WEBPACK_IMPORTED_MODULE_7__["default"], {
      type: notice.type,
      message: notice.message
    }), result && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsxs)("div", {
      className: "wppo-pagespeed-results",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)("div", {
        className: "wppo-score-gauges",
        children: Object.entries(result.scores ?? {}).map(([key, score]) => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)(ScoreGauge, {
          label: categoryLabels[key] ?? key,
          score: score
        }, key))
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsxs)("table", {
        className: "wppo-vitals-table",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)("thead", {
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsxs)("tr", {
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)("th", {
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('Metric', 'performance-optimisation')
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)("th", {
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('Value', 'performance-optimisation')
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)("th", {
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('Status', 'performance-optimisation')
            })]
          })
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)("tbody", {
          children: Object.entries(result.vitals ?? {}).map(([key, vital]) => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)(VitalRow, {
            label: vitalsLabels[key] ?? key,
            displayValue: vital.display_value,
            score: vital.score
          }, key))
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsxs)("p", {
        className: "wppo-pagespeed-meta",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_9__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faCheckCircle,
          className: "wppo-pagespeed-meta__icon"
        }), (result.strategy ?? strategy).toLowerCase() === 'desktop' ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('Desktop', 'performance-optimisation') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_8__.__)('Mobile', 'performance-optimisation'), ' · ', result.fetched_at]
      })]
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (PageSpeedPanel);

/***/ },

/***/ "./src/components/PerformanceAudit.js"
/*!********************************************!*\
  !*** ./src/components/PerformanceAudit.js ***!
  \********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @fortawesome/react-fontawesome */ "./node_modules/@fortawesome/react-fontawesome/index.es.js");
/* harmony import */ var _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @fortawesome/free-solid-svg-icons */ "./node_modules/@fortawesome/free-solid-svg-icons/index.mjs");
/* harmony import */ var _lib_apiRequest__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../lib/apiRequest */ "./src/lib/apiRequest.js");
/* harmony import */ var _lib_useNotice__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ../lib/useNotice */ "./src/lib/useNotice.js");
/* harmony import */ var _common_FeatureCard__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./common/FeatureCard */ "./src/components/common/FeatureCard.js");
/* harmony import */ var _common_StatusBadge__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! ./common/StatusBadge */ "./src/components/common/StatusBadge.js");
/* harmony import */ var _common_Tooltip__WEBPACK_IMPORTED_MODULE_7__ = __webpack_require__(/*! ./common/Tooltip */ "./src/components/common/Tooltip.js");
/* harmony import */ var _common_SwitchField__WEBPACK_IMPORTED_MODULE_8__ = __webpack_require__(/*! ./common/SwitchField */ "./src/components/common/SwitchField.js");
/* harmony import */ var _common_NoticeBanner__WEBPACK_IMPORTED_MODULE_9__ = __webpack_require__(/*! ./common/NoticeBanner */ "./src/components/common/NoticeBanner.js");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_10___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__);
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__);
/**
 * PerformanceAudit component.
 *
 * Provides a modern URL scan bar and detailed results categorized into
 * user-friendly metrics and advanced developer details.
 *
 * @since 1.5.0
 */













/**
 * Metric definitions with descriptions for tooltips.
 *
 * Values are functions so they can be translated at render time.
 */

const METRIC_INFO = {
  load_time: () => (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('The total time taken for the page to fully load in the browser.', 'performance-optimisation'),
  ttfb: () => (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Time to First Byte. The time it takes for the server to send the first byte of data.', 'performance-optimisation'),
  dns: () => (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('The time taken to resolve the domain name to an IP address.', 'performance-optimisation'),
  connect: () => (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('The time taken to establish a TCP connection with the server.', 'performance-optimisation'),
  ssl: () => (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('The time taken to complete the SSL/TLS handshake for secure connections.', 'performance-optimisation'),
  page_size: () => (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('The total weight of the page including CSS, JS, and Images.', 'performance-optimisation'),
  assets: () => (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('The total number of external resources loaded by the page.', 'performance-optimisation'),
  compression: () => (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Whether the server uses Gzip or Brotli to compress text assets.', 'performance-optimisation'),
  cache_control: () => (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Whether the server instructs the browser to cache assets for a long duration.', 'performance-optimisation'),
  modern_images: () => (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('The percentage of images on the page that use modern formats like WebP or AVIF.', 'performance-optimisation'),
  alt_text: () => (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Whether all images have descriptive alt attributes for accessibility.', 'performance-optimisation'),
  dom_size: () => (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('The total number of HTML elements on the page. High numbers (> 1,500) can slow down rendering.', 'performance-optimisation'),
  unminified: () => (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('The number of CSS and JS files that are not minified (lack .min in filename).', 'performance-optimisation'),
  third_party: () => (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('The number of scripts loaded from external domains (e.g., Google, Facebook).', 'performance-optimisation'),
  server_wait: () => (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Server processing time. The time taken by the server to process the request before sending data.', 'performance-optimisation')
};

/**
 * Derive a status string from a numeric value and thresholds.
 *
 * @param {number} value The metric value.
 * @param {number} good  Upper bound for 'good'.
 * @param {number} poor  Lower bound for 'poor'.
 * @return {string} Status string.
 */
const numericStatus = (value, good, poor) => {
  if (value <= good) {
    return 'good';
  }
  if (value <= poor) {
    return 'needs_improvement';
  }
  return 'poor';
};

/**
 * Derive a status string from a boolean pass/fail value.
 *
 * @param {boolean} passing Whether the check passed.
 * @return {string} 'good' or 'poor'.
 */
const boolStatus = passing => passing ? 'good' : 'poor';

/**
 * Format bytes into a human-readable string.
 *
 * @param {number} bytes Raw byte count.
 * @return {string} Formatted size string.
 */
const formatBytes = bytes => {
  if (!bytes || bytes === 0) {
    return '0 B';
  }
  if (bytes < 1024) {
    return `${bytes} B`;
  }
  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)} KB`;
  }
  return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
};

/**
 * A single row in the results table with optional tooltip.
 *
 * @param {Object} props
 * @param {string} props.label        Row label.
 * @param {string} props.value        Row value.
 * @param {string} [props.status]     Optional status badge.
 * @param {string} [props.tooltipKey] Key into METRIC_INFO for tooltip text.
 */
const ResultRow = ({
  label,
  value,
  status,
  tooltipKey
}) => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("tr", {
  className: "wppo-audit-table__row",
  children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("td", {
    className: "wppo-audit-table__label",
    children: [label, tooltipKey && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_common_Tooltip__WEBPACK_IMPORTED_MODULE_7__["default"], {
      content: METRIC_INFO[tooltipKey]?.()
    })]
  }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("td", {
    className: "wppo-audit-table__value",
    children: value
  }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("td", {
    className: "wppo-audit-table__status",
    children: status && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_common_StatusBadge__WEBPACK_IMPORTED_MODULE_6__["default"], {
      status: status
    })
  })]
});

/**
 * A section header row in the table.
 *
 * @param {Object} props
 * @param {string} props.title  Section title.
 * @param {Object} [props.icon] FontAwesome icon definition.
 */
const AuditSection = ({
  title,
  icon
}) => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("tr", {
  className: "wppo-audit-section-header",
  children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("td", {
    colSpan: "3",
    children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
      className: "wppo-audit-section-header__inner",
      children: [icon && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
        icon: icon
      }), title]
    })
  })
});

/**
 * Top-level overview cards showing the four key metrics at a glance.
 *
 * @param {Object} props
 * @param {Object} props.result Scan result from the REST API.
 */
const MetricOverview = ({
  result
}) => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
  className: "wppo-audit-overview",
  children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
    className: "wppo-audit-overview-card",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
      className: "wppo-audit-overview-card__label",
      children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Load Time', 'performance-optimisation'), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_common_Tooltip__WEBPACK_IMPORTED_MODULE_7__["default"], {
        content: METRIC_INFO.load_time()
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("span", {
      className: "wppo-audit-overview-card__value",
      children: [result.load_time, " s"]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("div", {
      className: "wppo-audit-overview-card__status",
      children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_common_StatusBadge__WEBPACK_IMPORTED_MODULE_6__["default"], {
        status: numericStatus(result.load_time, 2.5, 4)
      })
    })]
  }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
    className: "wppo-audit-overview-card",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
      className: "wppo-audit-overview-card__label",
      children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('TTFB', 'performance-optimisation'), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_common_Tooltip__WEBPACK_IMPORTED_MODULE_7__["default"], {
        content: METRIC_INFO.ttfb()
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("span", {
      className: "wppo-audit-overview-card__value",
      children: [result.ttfb, " ms"]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("div", {
      className: "wppo-audit-overview-card__status",
      children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_common_StatusBadge__WEBPACK_IMPORTED_MODULE_6__["default"], {
        status: numericStatus(result.ttfb, 200, 500)
      })
    })]
  }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
    className: "wppo-audit-overview-card",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
      className: "wppo-audit-overview-card__label",
      children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Page Size', 'performance-optimisation'), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_common_Tooltip__WEBPACK_IMPORTED_MODULE_7__["default"], {
        content: METRIC_INFO.page_size()
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("span", {
      className: "wppo-audit-overview-card__value",
      children: formatBytes(result.total_size)
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("div", {
      className: "wppo-audit-overview-card__status",
      children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_common_StatusBadge__WEBPACK_IMPORTED_MODULE_6__["default"], {
        status: numericStatus(result.total_size / 1024, 500, 1000)
      })
    })]
  }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
    className: "wppo-audit-overview-card",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
      className: "wppo-audit-overview-card__label",
      children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Total Assets', 'performance-optimisation'), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_common_Tooltip__WEBPACK_IMPORTED_MODULE_7__["default"], {
        content: METRIC_INFO.assets()
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("span", {
      className: "wppo-audit-overview-card__value",
      children: result.css_count + result.js_count + result.media_count
    })]
  })]
});
const PerformanceAudit = ({
  onSuggestionsReady,
  onUrlChange
}) => {
  const homeUrl = typeof wppoSettings !== 'undefined' ? wppoSettings.performance_audit?.homeUrl ?? '' : '';
  const [url, setUrl] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(homeUrl);
  const [scanning, setScanning] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [result, setResult] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [devMode, setDevMode] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const {
    notice,
    notify,
    dismiss
  } = (0,_lib_useNotice__WEBPACK_IMPORTED_MODULE_4__["default"])();
  const submittingRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(false);
  const abortControllerRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(null);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    return () => {
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
    };
  }, []);
  const handleDevModeToggle = e => {
    setDevMode(e.target.checked);
  };
  const handleScan = async (e, force = false) => {
    if (scanning || submittingRef.current) {
      return;
    }
    submittingRef.current = true;
    if (e) {
      e.preventDefault();
    }
    setScanning(true);
    dismiss();
    setResult(null);
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
    }
    abortControllerRef.current = new AbortController();
    const abortController = abortControllerRef.current;
    let scanResult = null;
    try {
      const response = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_3__.runPerformanceScan)(url, force, abortController.signal);
      if (!abortController.signal.aborted && response.success && response.data) {
        scanResult = response.data;
        setResult(scanResult);

        // Phase 2 — notify parent of the scanned URL so PageSpeedPanel
        // can use the same URL without the user having to re-enter it.
        if (onUrlChange) {
          onUrlChange(url);
        }
      } else if (!abortController.signal.aborted) {
        notify({
          type: 'error',
          message: response.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Scan failed. Please try again.', 'performance-optimisation')
        });
      }
    } catch (err) {
      if (abortController.signal.aborted) {
        return;
      }
      notify({
        type: 'error',
        message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Scan failed. Please try again.', 'performance-optimisation')
      });
      console.error('Performance scan error:', err);
    } finally {
      submittingRef.current = false;
      setScanning(false);
    }

    // Phase 2 — fetch telemetry-based suggestions after scan completes.
    if (onSuggestionsReady && scanResult) {
      try {
        const sugResp = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_3__.fetchSuggestions)(url, abortController.signal);
        if (!abortController.signal.aborted && sugResp.success && sugResp.data?.suggestions) {
          onSuggestionsReady(sugResp.data.suggestions);
        }
      } catch (sugErr) {
        if (!abortController.signal.aborted) {
          console.warn('Could not fetch suggestions:', sugErr);
        }
      }
    }
  };
  const setHomeUrl = () => {
    setUrl(homeUrl);
  };
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_5__["default"], {
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Performance Audit', 'performance-optimisation'),
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("form", {
      className: "wppo-audit-controls",
      onSubmit: handleScan,
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("div", {
        className: "wppo-audit-controls__icon",
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faSearch
        })
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("input", {
        id: "wppo-audit-url",
        type: "url",
        className: "wppo-audit-controls__input",
        value: url,
        onChange: e => setUrl(e.target.value),
        placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('https://example.com', 'performance-optimisation'),
        required: true,
        "aria-label": (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('URL to Audit', 'performance-optimisation')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
        className: "wppo-audit-controls__actions",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("button", {
          type: "button",
          className: "wppo-button wppo-button--ghost",
          onClick: setHomeUrl,
          title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Use Home URL', 'performance-optimisation'),
          "aria-label": (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Use Home URL', 'performance-optimisation'),
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
            icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faGlobe
          })
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("button", {
          type: "submit",
          className: "wppo-button wppo-button--primary",
          disabled: scanning,
          children: scanning ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Scanning…', 'performance-optimisation') : /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.Fragment, {
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
              icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faSearch,
              className: "wppo-mr-8"
            }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Run Scan', 'performance-optimisation')]
          })
        })]
      })]
    }), notice && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_common_NoticeBanner__WEBPACK_IMPORTED_MODULE_9__["default"], {
      type: notice.type,
      message: notice.message
    }), result && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
      className: "wppo-audit-results",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
        className: "wppo-audit-meta",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
          className: "wppo-audit-meta__title",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
            icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faChartBar,
            className: "wppo-mr-8"
          }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Scan Results', 'performance-optimisation')]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("div", {
          className: "wppo-audit-meta__toggle",
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_8__["default"], {
            checked: devMode,
            onChange: handleDevModeToggle,
            name: "devMode",
            label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Developer Details', 'performance-optimisation'),
            showLabel: false
          })
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(MetricOverview, {
        result: result
      }), result.is_cached && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
        className: "wppo-notice wppo-notice--info wppo-audit-cached-notice",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("span", {
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
            icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faLightbulb,
            className: "wppo-mr-8"
          }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Displaying cached results from the last hour.', 'performance-optimisation')]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("button", {
          type: "button",
          className: "wppo-button wppo-button--ghost wppo-button--sm",
          onClick: e => handleScan(e, true),
          disabled: scanning,
          "aria-label": (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Scan Fresh Data for Performance Audit', 'performance-optimisation'),
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Scan Fresh Data', 'performance-optimisation')
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("table", {
        className: "wppo-audit-table",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("thead", {
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("tr", {
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("th", {
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Metric', 'performance-optimisation')
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("th", {
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Value', 'performance-optimisation')
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("th", {
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Status', 'performance-optimisation')
            })]
          })
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("tbody", {
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(AuditSection, {
            title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Optimisations', 'performance-optimisation'),
            icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faCogs
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(ResultRow, {
            label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Gzip/Brotli Compression', 'performance-optimisation'),
            value: result.compression_value && result.compression_value !== 'none' ? result.compression_value : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Disabled', 'performance-optimisation'),
            status: boolStatus(result.gzip_brotli_compression),
            tooltipKey: "compression"
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(ResultRow, {
            label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Cache-Control', 'performance-optimisation'),
            value: result.cache_control_value && result.cache_control_value !== 'none' ? result.cache_control_value : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('None', 'performance-optimisation'),
            status: boolStatus(result.cache_control_headers),
            tooltipKey: "cache_control"
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(ResultRow, {
            label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Modern Formats', 'performance-optimisation'),
            value: `${Number(result.uses_modern_image_formats || 0).toFixed(1)}%`,
            status: numericStatus(100 - (parseFloat(result.uses_modern_image_formats) || 0), 20, 50),
            tooltipKey: "modern_images"
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(ResultRow, {
            label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Image Alt Attributes', 'performance-optimisation'),
            value: result.image_alt_attributes ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('All images have alt text', 'performance-optimisation') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Some images missing alt text', 'performance-optimisation'),
            status: boolStatus(result.image_alt_attributes),
            tooltipKey: "alt_text"
          }), devMode && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.Fragment, {
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(AuditSection, {
              title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Advanced Timings', 'performance-optimisation'),
              icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faTerminal
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(ResultRow, {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('DNS Lookup', 'performance-optimisation'),
              value: `${result.dns_lookup_time} ms`,
              tooltipKey: "dns"
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(ResultRow, {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('TCP Connection', 'performance-optimisation'),
              value: `${result.connect_time} ms`,
              tooltipKey: "connect"
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(ResultRow, {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('SSL Handshake', 'performance-optimisation'),
              value: `${result.ssl_time} ms`,
              tooltipKey: "ssl"
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(ResultRow, {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('True TTFB', 'performance-optimisation'),
              value: `${result.ttfb} ms`,
              tooltipKey: "ttfb"
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(ResultRow, {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Server Processing', 'performance-optimisation'),
              value: `${result.server_wait_time} ms`,
              tooltipKey: "server_wait"
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(AuditSection, {
              title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Asset Breakdown', 'performance-optimisation'),
              icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faChartBar
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(ResultRow, {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('CSS Files', 'performance-optimisation'),
              value: `${result.css_count} (${formatBytes(result.css_total_size)})`
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(ResultRow, {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('JS Files', 'performance-optimisation'),
              value: `${result.js_count} (${formatBytes(result.js_total_size)})`
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(ResultRow, {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Total Images', 'performance-optimisation'),
              value: `${result.media_count} (${formatBytes(result.media_total_size)})`
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(ResultRow, {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Lazy-Loaded', 'performance-optimisation'),
              value: result.lazy_image_count
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(ResultRow, {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Eager-Loaded', 'performance-optimisation'),
              value: result.eager_image_count
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(ResultRow, {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Total DOM Nodes', 'performance-optimisation'),
              value: result.dom_size,
              tooltipKey: "dom_size"
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(ResultRow, {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Unminified Assets', 'performance-optimisation'),
              value: result.unminified_assets_count,
              tooltipKey: "unminified"
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(ResultRow, {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Third-Party Scripts', 'performance-optimisation'),
              value: result.third_party_scripts_count,
              tooltipKey: "third_party"
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(AuditSection, {
              title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Environment', 'performance-optimisation'),
              icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faGlobe
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(ResultRow, {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Page URL', 'performance-optimisation'),
              value: result.page_url
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(ResultRow, {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Scan Type', 'performance-optimisation'),
              value: result.scan_type
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(ResultRow, {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('HTTPS', 'performance-optimisation'),
              value: result.uses_https ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Enabled', 'performance-optimisation') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Disabled', 'performance-optimisation'),
              status: boolStatus(result.uses_https)
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(ResultRow, {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('robots.txt', 'performance-optimisation'),
              value: result.robots_txt_exists ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Exists', 'performance-optimisation') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Missing', 'performance-optimisation'),
              status: boolStatus(result.robots_txt_exists)
            })]
          })]
        })]
      }), !devMode && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
        className: "wppo-notice wppo-notice--info wppo-mt-24",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faLightbulb,
          className: "wppo-mr-8"
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("span", {
          children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Enable', 'performance-optimisation'), ' ', /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("strong", {
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Developer Details', 'performance-optimisation')
          }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('for granular network timings and environment info.', 'performance-optimisation')]
        })]
      })]
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (PerformanceAudit);

/***/ },

/***/ "./src/components/RecentActivityCard.js"
/*!**********************************************!*\
  !*** ./src/components/RecentActivityCard.js ***!
  \**********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @fortawesome/react-fontawesome */ "./node_modules/@fortawesome/react-fontawesome/index.es.js");
/* harmony import */ var _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! @fortawesome/free-solid-svg-icons */ "./node_modules/@fortawesome/free-solid-svg-icons/index.mjs");
/* harmony import */ var _common_FeatureCard__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ./common/FeatureCard */ "./src/components/common/FeatureCard.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__);
/**
 * RecentActivityCard component.
 *
 * Shows the 5 most recent optimization activities on the Dashboard.
 * The "View Full Log" button navigates to the Tools tab where the
 * complete paginated activity log lives.
 *
 * @since 1.5.0
 */







const RecentActivityCard = ({
  activities,
  onNavigate
}) => {
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_4__["default"], {
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Recent Optimisation Activity', 'performance-optimisation'),
    icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_2__.FontAwesomeIcon, {
      icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_3__.faHistory
    }),
    footer: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("button", {
      type: "button",
      className: "wppo-button wppo-button--secondary",
      onClick: () => onNavigate('tools'),
      "aria-label": (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('View Full Optimisation Activity Log', 'performance-optimisation'),
      children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('View Full Log', 'performance-optimisation'), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_2__.FontAwesomeIcon, {
        icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_3__.faArrowRight
      })]
    }),
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("p", {
      className: "wppo-text-muted wppo-text-small wppo-mb-16",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('The 5 most recent actions performed by the plugin. Open the Tools tab for the complete paginated log.', 'performance-optimisation')
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("div", {
      className: "wppo-activity-wrapper",
      children: activities?.length ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("ul", {
        className: "wppo-activity-list",
        children: activities.slice(0, 5).map(activity => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("li", {
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("span", {
            className: "wppo-activity-text",
            children: activity.activity
          })
        }, activity.id))
      }) : /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("div", {
        className: "wppo-empty-state",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('No optimisation activity recorded yet.', 'performance-optimisation')
      })
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = ((0,_wordpress_element__WEBPACK_IMPORTED_MODULE_1__.memo)(RecentActivityCard));

/***/ },

/***/ "./src/components/SuggestionsPanel.js"
/*!********************************************!*\
  !*** ./src/components/SuggestionsPanel.js ***!
  \********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @fortawesome/react-fontawesome */ "./node_modules/@fortawesome/react-fontawesome/index.es.js");
/* harmony import */ var _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @fortawesome/free-solid-svg-icons */ "./node_modules/@fortawesome/free-solid-svg-icons/index.mjs");
/* harmony import */ var _common_StatusBadge__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./common/StatusBadge */ "./src/components/common/StatusBadge.js");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_4___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__);
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__);
/**
 * SuggestionsPanel component.
 *
 * Renders one card per suggestion returned by the Suggestion_Engine.
 * Cards with 'poor' or 'needs_improvement' status show a "Fix It" button
 * that navigates the user directly to the relevant WPPO tab.
 * Cards with 'good' status show a passing indicator instead.
 *
 * Sits inside the Dashboard tab, directly below <PerformanceAudit />,
 * so the user sees diagnosis and remedy on the same screen.
 *
 * @since 1.6.0
 */







/**
 * Maps fix_action values to WPPO sidebar tab names.
 * Must stay in sync with App.js sidebarItems names.
 *
 * @type {Object.<string, string>}
 */

const FIX_ACTION_TAB_MAP = {
  open_object_cache_tab: 'objectCache',
  open_image_optimization_tab: 'imageOptimization',
  open_file_optimization_tab: 'fileOptimization',
  open_ccss_settings: 'fileOptimization',
  enable_server_rules: 'fileOptimization',
  open_preload_tab: 'preload',
  no_action_required: null
};

/**
 * Status icon for a suggestion card.
 *
 * @param {Object} props
 * @param {string} props.status 'good' | 'needs_improvement' | 'poor'
 */
const SuggestionIcon = ({
  status
}) => {
  if (status === 'good') {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
      icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faCheckCircle,
      className: "wppo-suggestion-icon wppo-suggestion-icon--good"
    });
  }
  if (status === 'needs_improvement') {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
      icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faExclamationTriangle,
      className: "wppo-suggestion-icon wppo-suggestion-icon--warning"
    });
  }
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
    icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faTimesCircle,
    className: "wppo-suggestion-icon wppo-suggestion-icon--poor"
  });
};

/**
 * Format a suggestion value for display.
 *
 * @param {*}      value Metric value.
 * @param {string} unit  Unit label.
 * @return {string} Formatted display string.
 */
const formatValue = (value, unit) => {
  if (unit === 'boolean') {
    return value === 'pass' ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('Passing', 'performance-optimisation') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('Failing', 'performance-optimisation');
  }
  if (unit === 'header') {
    if (value === 'none') {
      return (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('None', 'performance-optimisation');
    }
    // Always show Cache-Control value as-is, never translate the header text.
    return value;
  }
  if (unit === 'encoding') {
    if (value === 'none') {
      return (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('None', 'performance-optimisation');
    }
    // Map raw content-encoding values to human-readable form.
    const encodings = {
      br: 'Brotli',
      gzip: 'Gzip',
      deflate: 'Deflate',
      zstd: 'Zstd'
    };
    return encodings[String(value).toLowerCase()] || value;
  }
  if (unit === 'score') {
    return `${Math.round(parseFloat(value) * 100)} / 100`;
  }
  if (unit === '%') {
    return `${Number(value).toFixed(1)}%`;
  }
  if (unit === 's') {
    return `${Number(value).toFixed(2)}s`;
  }
  if (unit === 'ms') {
    return `${Math.round(value)}ms`;
  }
  return `${value} ${unit}`;
};

/**
 * A single suggestion card.
 *
 * @param {Object}   props
 * @param {Object}   props.suggestion Suggestion object from Suggestion_Engine.
 * @param {Function} props.onNavigate Callback to switch the active WPPO tab.
 */
const SuggestionCard = ({
  suggestion,
  onNavigate
}) => {
  const {
    value,
    unit,
    status,
    description,
    fix_action: fixAction
  } = suggestion;
  const targetTab = FIX_ACTION_TAB_MAP[fixAction] ?? null;
  const canFix = status !== 'good' && targetTab !== null;
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("div", {
    className: `wppo-suggestion-card wppo-suggestion-card--${status}`,
    role: "listitem",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("div", {
      className: "wppo-suggestion-card__header",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(SuggestionIcon, {
        status: status
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("span", {
        className: "wppo-suggestion-card__description",
        children: description
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(_common_StatusBadge__WEBPACK_IMPORTED_MODULE_3__["default"], {
        status: status
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("div", {
      className: "wppo-suggestion-card__body",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("span", {
        className: "wppo-suggestion-card__value",
        children: formatValue(value, unit)
      }), canFix && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("button", {
        type: "button",
        className: "wppo-button wppo-button--sm wppo-button--primary",
        onClick: () => onNavigate(targetTab),
        "aria-label": `${(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('Fix It', 'performance-optimisation')}: ${description}`,
        children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('Fix It', 'performance-optimisation'), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faArrowRight,
          className: "wppo-ml-6"
        })]
      }), !canFix && status === 'good' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("span", {
        className: "wppo-suggestion-card__passing",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faCheckCircle,
          className: "wppo-mr-4"
        }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('Passing', 'performance-optimisation')]
      })]
    })]
  });
};

/**
 * SuggestionsPanel
 *
 * Renders the full suggestions list. Shown in the Dashboard tab directly
 * below <PerformanceAudit /> after a scan completes.
 *
 * @param {Object}   props
 * @param {Array}    props.suggestions Array of suggestion objects.
 * @param {Function} props.onNavigate  Callback to switch the active WPPO tab.
 */
const SuggestionsPanel = ({
  suggestions,
  onNavigate
}) => {
  if (!suggestions || suggestions.length === 0) {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("div", {
      className: "wppo-suggestions-panel wppo-suggestions-panel--empty",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
        icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faCheckCircle,
        className: "wppo-suggestions-panel__empty-icon"
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("p", {
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('No suggestions — your site looks great!', 'performance-optimisation')
      })]
    });
  }
  const issues = suggestions.filter(s => s.status !== 'good');
  const passing = suggestions.filter(s => s.status === 'good');
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("div", {
    className: "wppo-suggestions-panel",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("div", {
      className: "wppo-suggestions-panel__header",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
        icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faLightbulb,
        className: "wppo-mr-8"
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("h3", {
        className: "wppo-suggestions-panel__title",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('Suggestions', 'performance-optimisation')
      }), issues.length > 0 && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("span", {
        className: "wppo-suggestions-panel__badge",
        children: issues.length
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)("p", {
      className: "wppo-suggestions-panel__desc",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('Based on your scan results, here are the recommended actions to improve performance.', 'performance-optimisation')
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsxs)("div", {
      className: "wppo-suggestions-panel__list",
      role: "list",
      "aria-label": (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_4__.__)('Suggestions', 'performance-optimisation'),
      children: [issues.map(suggestion => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(SuggestionCard, {
        suggestion: suggestion,
        onNavigate: onNavigate
      }, suggestion.metric)), passing.map(suggestion => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_5__.jsx)(SuggestionCard, {
        suggestion: suggestion,
        onNavigate: onNavigate
      }, suggestion.metric))]
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = ((0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.memo)(SuggestionsPanel));

/***/ },

/***/ "./src/components/SystemInfo.js"
/*!**************************************!*\
  !*** ./src/components/SystemInfo.js ***!
  \**************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _lib_apiRequest__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ../lib/apiRequest */ "./src/lib/apiRequest.js");
/* harmony import */ var _lib_useNotice__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../lib/useNotice */ "./src/lib/useNotice.js");
/* harmony import */ var _common_FeatureCard__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./common/FeatureCard */ "./src/components/common/FeatureCard.js");
/* harmony import */ var _common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ./common/LoadingSubmitButton */ "./src/components/common/LoadingSubmitButton.js");
/* harmony import */ var _common_NoticeBanner__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./common/NoticeBanner */ "./src/components/common/NoticeBanner.js");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_6___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__);
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__);
/**
 * SystemInfo component.
 *
 * Renders server/PHP/WP environment details in a tabular layout.
 * Data is fetched on-demand when the user clicks "Load System Info"
 * rather than automatically on mount, to keep the Dashboard fast.
 *
 * @since 1.5.0
 */









/**
 * A single key-value row in a system info table.
 *
 * @param {Object} props
 * @param {string} props.label Row label.
 * @param {*}      props.value Row value (null renders as '—').
 */

const InfoRow = ({
  label,
  value
}) => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsxs)("tr", {
  className: "wppo-sysinfo-table__row",
  children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)("td", {
    className: "wppo-sysinfo-table__label",
    children: label
  }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)("td", {
    className: "wppo-sysinfo-table__value",
    children: value !== null && value !== undefined && value !== '' ? String(value) : '—'
  })]
});

/**
 * A labelled table of InfoRow items.
 *
 * @param {Object} props
 * @param {string} props.title  Section heading.
 * @param {Object} props.data   Key-value pairs to render.
 * @param {Object} props.labels Optional map of data keys to display labels.
 */
const InfoTable = ({
  title,
  data,
  labels = {}
}) => {
  if (!data) {
    return null;
  }
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsxs)("div", {
    className: "wppo-sysinfo-section",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)("h4", {
      className: "wppo-sysinfo-section__title",
      children: title
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)("table", {
      className: "wppo-sysinfo-table",
      children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)("tbody", {
        children: Object.entries(data).map(([key, value]) => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)(InfoRow, {
          label: labels[key] || key,
          value: value
        }, key))
      })
    })]
  });
};
const SystemInfo = () => {
  const [info, setInfo] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [loading, setLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [loaded, setLoaded] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const {
    notice,
    notify,
    dismiss
  } = (0,_lib_useNotice__WEBPACK_IMPORTED_MODULE_2__["default"])();
  const handleLoad = async () => {
    setLoading(true);
    dismiss();
    try {
      const response = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_1__.fetchSystemInfo)();
      if (response.success && response.data) {
        setInfo(response.data);
        setLoaded(true);
      } else {
        notify({
          type: 'error',
          message: response.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Failed to fetch system info. Please try again.', 'performance-optimisation')
        });
      }
    } catch (err) {
      notify({
        type: 'error',
        message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Failed to fetch system info. Please try again.', 'performance-optimisation')
      });
      console.error('System info fetch error:', err);
    } finally {
      setLoading(false);
    }
  };
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsxs)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_3__["default"], {
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('System Info', 'performance-optimisation'),
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsxs)("div", {
      className: `wppo-sysinfo-trigger ${loaded ? 'wppo-sysinfo-trigger--compact' : ''}`,
      children: [!loaded && !notice && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)("p", {
        id: "wppo-sysinfo-desc",
        className: "wppo-sysinfo-trigger__desc",
        children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('View PHP, database, WordPress, and server environment details.', 'performance-optimisation')
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_4__["default"], {
        type: "button",
        className: "wppo-button wppo-button--secondary",
        onClick: handleLoad,
        "aria-describedby": !loaded && !notice ? 'wppo-sysinfo-desc' : undefined,
        isLoading: loading,
        label: loaded ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Refresh', 'performance-optimisation') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Load System Info', 'performance-optimisation'),
        loadingLabel: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Loading…', 'performance-optimisation')
      })]
    }), notice && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)(_common_NoticeBanner__WEBPACK_IMPORTED_MODULE_5__["default"], {
      type: notice.type,
      message: notice.message
    }), info && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsxs)("div", {
      className: "wppo-sysinfo-grid",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)(InfoTable, {
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('PHP', 'performance-optimisation'),
        data: info.php,
        labels: {
          version: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('PHP Version', 'performance-optimisation'),
          sapi: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('SAPI', 'performance-optimisation'),
          memory_limit: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Memory Limit', 'performance-optimisation'),
          max_execution_time: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Max Execution Time', 'performance-optimisation'),
          upload_max_filesize: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Upload Max Filesize', 'performance-optimisation'),
          post_max_size: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Post Max Size', 'performance-optimisation'),
          display_errors: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Display Errors', 'performance-optimisation'),
          extensions_count: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Extensions Loaded', 'performance-optimisation')
        }
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)(InfoTable, {
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Database', 'performance-optimisation'),
        data: info.database,
        labels: {
          server_version: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('DB Version', 'performance-optimisation'),
          extension: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Extension', 'performance-optimisation'),
          client_version: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Client Version', 'performance-optimisation'),
          max_connections: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Max Connections', 'performance-optimisation')
        }
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)(InfoTable, {
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('WordPress', 'performance-optimisation'),
        data: info.wordpress,
        labels: {
          version: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('WP Version', 'performance-optimisation'),
          environment_type: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Environment', 'performance-optimisation'),
          permalink_structure: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Permalink Structure', 'performance-optimisation'),
          using_https: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('HTTPS', 'performance-optimisation'),
          multisite: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Multisite', 'performance-optimisation')
        }
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)(InfoTable, {
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Server', 'performance-optimisation'),
        data: info.server,
        labels: {
          server_software: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Server Software', 'performance-optimisation'),
          os: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Operating System', 'performance-optimisation'),
          architecture: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Architecture', 'performance-optimisation')
        }
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)(InfoTable, {
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Cache', 'performance-optimisation'),
        data: {
          object_cache_status: info.cache?.object_cache_status,
          active_cache_plugin: info.cache?.active_cache_plugin,
          peak_memory_usage: info.cache?.peak_memory_usage,
          current_memory_usage: info.cache?.current_memory_usage
        },
        labels: {
          object_cache_status: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Object Cache', 'performance-optimisation'),
          active_cache_plugin: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Active Cache Plugin', 'performance-optimisation'),
          peak_memory_usage: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Peak Memory', 'performance-optimisation'),
          current_memory_usage: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Current Memory', 'performance-optimisation')
        }
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)(InfoTable, {
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('OPCache', 'performance-optimisation'),
        data: info.opcache,
        labels: {
          status: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Status', 'performance-optimisation'),
          memory_usage: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Memory Usage', 'performance-optimisation'),
          interned_strings: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Interned Strings', 'performance-optimisation'),
          hit_rate: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Hit Rate', 'performance-optimisation'),
          cache_full: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Cache Full', 'performance-optimisation'),
          detail: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Detail', 'performance-optimisation')
        }
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)(InfoTable, {
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Infrastructure', 'performance-optimisation'),
        data: {
          action_scheduler: info.infrastructure?.action_scheduler?.available ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Available', 'performance-optimisation') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Unavailable', 'performance-optimisation'),
          pagespeed_api: info.infrastructure?.pagespeed_api?.configured ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Configured', 'performance-optimisation') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Not Configured', 'performance-optimisation')
        },
        labels: {
          action_scheduler: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('Action Scheduler', 'performance-optimisation'),
          pagespeed_api: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('PageSpeed Insights API', 'performance-optimisation')
        }
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)(InfoTable, {
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_6__.__)('WP Constants', 'performance-optimisation'),
        data: info.wp_constants
      })]
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = ((0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.memo)(SystemInfo));

/***/ },

/***/ "./src/components/WebVitalsRum.js"
/*!****************************************!*\
  !*** ./src/components/WebVitalsRum.js ***!
  \****************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @fortawesome/react-fontawesome */ "./node_modules/@fortawesome/react-fontawesome/index.es.js");
/* harmony import */ var _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! @fortawesome/free-solid-svg-icons */ "./node_modules/@fortawesome/free-solid-svg-icons/index.mjs");
/* harmony import */ var _lib_apiRequest__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ../lib/apiRequest */ "./src/lib/apiRequest.js");
/* harmony import */ var _common_FeatureCard__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./common/FeatureCard */ "./src/components/common/FeatureCard.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__);
/**
 * WebVitalsRum component.
 *
 * Renders aggregated real-user Core Web Vitals (LCP/INP/CLS/FCP/TTFB) collected
 * from real visitors, grouped by day. Fetches GET /rum_data on mount.
 *
 * @since 2.18.0
 */








/**
 * Aggregate all paths for a day into site-wide metric averages.
 *
 * @param {Object} day Aggregates keyed by path.
 * @return {Object} Per-metric { n, avg }.
 */

const dayAverages = day => {
  const totals = {};
  for (const path of Object.values(day)) {
    for (const [metric, bucket] of Object.entries(path)) {
      if (!totals[metric]) {
        totals[metric] = {
          n: 0,
          sum: 0
        };
      }
      totals[metric].n += bucket.n;
      totals[metric].sum += bucket.sum;
    }
  }
  const averages = {};
  for (const [metric, total] of Object.entries(totals)) {
    averages[metric] = total.n ? total.sum / total.n : null;
  }
  return averages;
};

/**
 * @return {Element} The RUM panel.
 */
const WebVitalsRum = () => {
  const [data, setData] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)([]);
  const [loading, setLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(true);
  const [error, setError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const load = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_4__.apiCall)('rum_data', {}, 'GET');
      if (response.success && response.data) {
        const rows = Object.entries(response.data).sort(([a], [b]) => a.localeCompare(b)).map(([day, paths]) => ({
          day,
          ...dayAverages(paths)
        })).slice(-14);
        setData(rows);
      } else {
        setError(response.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to load real-user data.', 'performance-optimisation'));
      }
    } catch (loadError) {
      setError((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to load real-user data.', 'performance-optimisation'));
      console.error('Error fetching RUM data:', loadError);
    } finally {
      setLoading(false);
    }
  }, []);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    load();
  }, [load]);
  const fmtMs = value => value === null || value === undefined ? '—' : `${Math.round(value)} ms`;
  const fmtCls = value => value === null || value === undefined ? '—' : value.toFixed(3);
  let body;
  if (error) {
    body = /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("p", {
      className: "wppo-text-muted",
      children: error
    });
  } else if (data.length === 0 && !loading) {
    body = /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("p", {
      className: "wppo-text-muted",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('No real-user data yet. Enable "Collect Real-user Web Vitals" in Tools and wait for visitors.', 'performance-optimisation')
    });
  } else {
    body = /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("table", {
      className: "wppo-rum-table wppo-table",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("thead", {
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("tr", {
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("th", {
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Day', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("th", {
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('LCP', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("th", {
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('INP', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("th", {
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('CLS', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("th", {
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('FCP', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("th", {
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('TTFB', 'performance-optimisation')
          })]
        })
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("tbody", {
        children: data.map(row => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("tr", {
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("td", {
            children: row.day
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("td", {
            children: fmtMs(row.lcp)
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("td", {
            children: fmtMs(row.inp)
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("td", {
            children: fmtCls(row.cls)
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("td", {
            children: fmtMs(row.fcp)
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("td", {
            children: fmtMs(row.ttfb)
          })]
        }, row.day))
      })]
    });
  }
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_5__["default"], {
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Real-user Web Vitals', 'performance-optimisation'),
    icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_2__.FontAwesomeIcon, {
      icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_3__.faUsers
    }),
    actions: loading && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_2__.FontAwesomeIcon, {
      icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_3__.faSpinner,
      spin: true,
      "aria-label": (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Loading…', 'performance-optimisation')
    }),
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("p", {
      className: "wppo-text-muted",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Aggregated Core Web Vitals from real visitors, per day (site-wide).', 'performance-optimisation')
    }), body, data.length > 0 && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("p", {
      className: "wppo-text-muted wppo-text-small",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.sprintf)(/* translators: %d: number of sample days retained */
      (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Showing up to %d days.', 'performance-optimisation'), 14)
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (WebVitalsRum);

/***/ },

/***/ "./src/components/WebVitalsTrends.js"
/*!*******************************************!*\
  !*** ./src/components/WebVitalsTrends.js ***!
  \*******************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @fortawesome/react-fontawesome */ "./node_modules/@fortawesome/react-fontawesome/index.es.js");
/* harmony import */ var _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! @fortawesome/free-solid-svg-icons */ "./node_modules/@fortawesome/free-solid-svg-icons/index.mjs");
/* harmony import */ var _lib_apiRequest__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ../lib/apiRequest */ "./src/lib/apiRequest.js");
/* harmony import */ var _common_FeatureCard__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./common/FeatureCard */ "./src/components/common/FeatureCard.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__);
/**
 * WebVitalsTrends component.
 *
 * Renders historical PageSpeed performance scores as an inline SVG line chart
 * with no external chart library. Fetches trend data from
 * GET /web_vitals_trends on mount, scoped to the current audit URL.
 *
 * @since 2.14.0
 */








const SPARK_WIDTH = 640;
const SPARK_HEIGHT = 160;
const PAD_X = 10;
const PAD_Y = 26;

/**
 * Builds an SVG polyline points string from a numeric series.
 *
 * @param {Array<number>} values Series of numeric values (0–100).
 * @return {string} Points string for an SVG polyline.
 */
const buildPoints = values => {
  if (!values || values.length < 1) {
    return '';
  }
  const max = Math.max(...values, 100);
  const min = Math.min(...values, 0);
  const range = max - min || 1;
  const stepX = (SPARK_WIDTH - PAD_X * 2) / Math.max(values.length - 1, 1);
  return values.map((value, index) => {
    const x = PAD_X + index * stepX;
    const y = SPARK_HEIGHT - PAD_Y - (value - min) / range * (SPARK_HEIGHT - PAD_Y * 2);
    return `${x},${y.toFixed(1)}`;
  }).join(' ');
};

/**
 * Renders one strategy series (mobile or desktop).
 *
 * @param {Object} props
 * @param {string} props.strategy 'mobile' or 'desktop'.
 * @param {Object} props.trends   Trends object from the API.
 */
const TrendSeries = ({
  strategy,
  trends
}) => {
  const seriesKey = Object.keys(trends).find(key => key.endsWith(`_${strategy}`));
  if (!seriesKey) {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("p", {
      className: "wppo-text-muted wppo-text-small wppo-mt-8",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Not enough trend data yet. Run PageSpeed scans over time or enable auto-rescan.', 'performance-optimisation')
    });
  }
  const snapshots = trends[seriesKey] ?? [];
  const values = snapshots.map(snap => snap.performance);
  const last = snapshots.length > 0 ? snapshots[snapshots.length - 1].performance : null;
  const points = buildPoints(values);
  const limited = snapshots.length > 1;
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
    className: "wppo-trend-series",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
      className: "wppo-trend-series__header",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("span", {
        className: "wppo-trend-series__label",
        children: strategy === 'mobile' ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Mobile', 'performance-optimisation') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Desktop', 'performance-optimisation')
      }), last !== null && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("span", {
        className: "wppo-trend-series__latest",
        children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Latest score', 'performance-optimisation'), ': ', /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("strong", {
          children: last
        })]
      })]
    }), limited ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("svg", {
      className: "wppo-trend-chart",
      viewBox: `0 0 ${SPARK_WIDTH} ${SPARK_HEIGHT}`,
      role: "img",
      "aria-label": (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.sprintf)(/* translators: %s: strategy (mobile or desktop) */
      (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('%s performance score trend chart', 'performance-optimisation'), strategy),
      children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("polyline", {
        className: "wppo-trend-chart__line",
        points: points,
        strokeWidth: "2",
        fill: "none"
      })
    }) : /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("p", {
      className: "wppo-text-muted wppo-text-small wppo-mt-8",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Not enough trend data yet. Run a few PageSpeed scans over time.', 'performance-optimisation')
    })]
  });
};
const WebVitalsTrends = ({
  url = ''
}) => {
  const [trends, setTrends] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [loading, setLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(true);
  const [error, setError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const loadTrends = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(async () => {
    if (!url) {
      // Without a known URL the request would return every history and
      // TrendSeries would mislabel them; show an explicit empty state.
      setTrends(null);
      setError(null);
      setLoading(false);
      return;
    }
    setLoading(true);
    setError(null);
    try {
      const response = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_4__.fetchWebVitalsTrends)(url, '');
      if (response.success) {
        setTrends(response.data?.trends ?? {});
      } else {
        setError(response.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to load trend data.', 'performance-optimisation'));
      }
    } catch (err) {
      setError((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to load trend data.', 'performance-optimisation'));
      console.error('Web Vitals trends load error:', err);
    } finally {
      setLoading(false);
    }
  }, [url]);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    loadTrends();
  }, [loadTrends]);
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_5__["default"], {
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Web Vitals Trends', 'performance-optimisation'),
    children: [loading && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("p", {
      className: "wppo-text-muted",
      role: "status",
      "aria-live": "polite",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_2__.FontAwesomeIcon, {
        icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_3__.faSpinner,
        spin: true,
        className: "wppo-mr-8"
      }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Loading trends…', 'performance-optimisation')]
    }), !loading && error && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
      className: "wppo-notice wppo-notice--error",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_2__.FontAwesomeIcon, {
        icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_3__.faExclamationCircle,
        className: "wppo-mr-8"
      }), error]
    }), !loading && !error && !url && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("p", {
      className: "wppo-text-muted",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Enter a URL to view Web Vitals trend history.', 'performance-optimisation')
    }), !loading && !error && url && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
      className: "wppo-trend-layout",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
        className: "wppo-trend-layout__title",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_2__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_3__.faChartLine,
          className: "wppo-mr-8"
        }), url]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(TrendSeries, {
        strategy: "mobile",
        trends: trends ?? {}
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(TrendSeries, {
        strategy: "desktop",
        trends: trends ?? {}
      })]
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (WebVitalsTrends);

/***/ },

/***/ "./src/components/WelcomePanel.js"
/*!****************************************!*\
  !*** ./src/components/WelcomePanel.js ***!
  \****************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__);
/* harmony import */ var _lib_apiRequest__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../lib/apiRequest */ "./src/lib/apiRequest.js");
/* harmony import */ var _lib_useNotice__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../lib/useNotice */ "./src/lib/useNotice.js");
/* harmony import */ var _common_FeatureCard__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ./common/FeatureCard */ "./src/components/common/FeatureCard.js");
/* harmony import */ var _common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./common/LoadingSubmitButton */ "./src/components/common/LoadingSubmitButton.js");
/* harmony import */ var _common_NoticeBanner__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! ./common/NoticeBanner */ "./src/components/common/NoticeBanner.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__);








const STEPS = [{
  number: 1,
  key: 'cache',
  label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Enable Page Caching', 'performance-optimisation'),
  description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Speed up your site with static HTML page caching — the single biggest performance win.', 'performance-optimisation'),
  settings: {
    tab: 'cache_settings',
    payload: {
      enableCache: true
    }
  },
  isEnabled: () => wppoSettings?.settings?.cache_settings?.enableCache ?? false
}, {
  number: 2,
  key: 'minify',
  label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Enable JS / CSS Minification', 'performance-optimisation'),
  description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Reduce file sizes by removing whitespace and comments from your CSS and JavaScript.', 'performance-optimisation'),
  settings: {
    tab: 'file_optimisation',
    payload: {
      minifyJS: true,
      minifyCSS: true
    }
  },
  isEnabled: () => (wppoSettings?.settings?.file_optimisation?.minifyJS ?? false) && (wppoSettings?.settings?.file_optimisation?.minifyCSS ?? false)
}, {
  number: 3,
  key: 'lazyload',
  label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Enable Lazy Loading', 'performance-optimisation'),
  description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Defer off-screen images and videos so they only load when visitors scroll to them.', 'performance-optimisation'),
  settings: {
    tab: 'image_optimisation',
    payload: {
      lazyLoadImages: true
    }
  },
  isEnabled: () => wppoSettings?.settings?.image_optimisation?.lazyLoadImages ?? false
}];
const WelcomePanel = () => {
  const [visible, setVisible] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(wppoSettings?.show_welcome ?? false);
  const [activatingStep, setActivatingStep] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [dismissing, setDismissing] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const {
    notice,
    notify,
    dismiss
  } = (0,_lib_useNotice__WEBPACK_IMPORTED_MODULE_3__["default"])();
  if (!visible) {
    return null;
  }
  const handleStepAction = async step => {
    setActivatingStep(step.key);
    dismiss();
    try {
      const updateRes = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_2__.apiCall)('update_settings', {
        tab: step.settings.tab,
        settings: step.settings.payload
      });
      const dismissRes = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_2__.apiCall)('dismiss_welcome');
      if (updateRes.success && dismissRes.success) {
        setVisible(false);
      } else {
        notify({
          type: 'error',
          message: !updateRes.success && updateRes.message || !dismissRes.success && dismissRes.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to enable the feature.', 'performance-optimisation'),
          durationMs: 5000
        });
      }
    } catch (error) {
      console.error('Welcome panel action failed:', error);
      notify({
        type: 'error',
        message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to enable the feature.', 'performance-optimisation'),
        durationMs: 5000
      });
    } finally {
      setActivatingStep(null);
    }
  };
  const handleDismiss = async () => {
    setDismissing(true);
    dismiss();
    try {
      const res = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_2__.apiCall)('dismiss_welcome');
      if (res.success) {
        setVisible(false);
      } else {
        notify({
          type: 'error',
          message: res.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to dismiss the welcome panel.', 'performance-optimisation'),
          durationMs: 5000
        });
      }
    } catch (error) {
      console.error('Welcome dismiss failed:', error);
      notify({
        type: 'error',
        message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Failed to dismiss the welcome panel.', 'performance-optimisation'),
        durationMs: 5000
      });
    } finally {
      setDismissing(false);
    }
  };
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsxs)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_4__["default"], {
    className: "wppo-welcome-panel",
    title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Welcome to Performance Optimisation', 'performance-optimisation'),
    footer: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_5__["default"], {
      type: "button",
      className: "wppo-button wppo-button--secondary",
      onClick: handleDismiss,
      isLoading: dismissing,
      label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Got it', 'performance-optimisation'),
      loadingLabel: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Dismissing…', 'performance-optimisation')
    }),
    children: [notice && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)(_common_NoticeBanner__WEBPACK_IMPORTED_MODULE_6__["default"], {
      type: notice.type,
      message: notice.message,
      onDismiss: dismiss
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)("p", {
      className: "wppo-welcome-panel__intro",
      children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Get started in 3 quick steps. Each toggle below activates a key performance feature — no page reload needed.', 'performance-optimisation')
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)("div", {
      className: "wppo-welcome-steps",
      children: STEPS.map(step => {
        const enabled = step.isEnabled();
        return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsxs)("div", {
          className: `wppo-welcome-step${enabled ? ' wppo-welcome-step--done' : ''}`,
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)("span", {
            className: "wppo-welcome-step__number",
            children: enabled ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)("svg", {
              width: "16",
              height: "16",
              viewBox: "0 0 16 16",
              fill: "none",
              "aria-hidden": "true",
              children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)("path", {
                d: "M13.3 4.3L6 11.6 2.7 8.3",
                stroke: "currentColor",
                strokeWidth: "2",
                strokeLinecap: "round",
                strokeLinejoin: "round"
              })
            }) : step.number
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsxs)("div", {
            className: "wppo-welcome-step__content",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)("strong", {
              className: "wppo-welcome-step__label",
              children: step.label
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)("p", {
              className: "wppo-welcome-step__desc",
              children: step.description
            })]
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)("div", {
            className: "wppo-welcome-step__action",
            children: enabled ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)("span", {
              className: "wppo-welcome-step__check",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Active', 'performance-optimisation')
            }) : /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_7__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_5__["default"], {
              type: "button",
              className: "wppo-button wppo-button--primary",
              isLoading: activatingStep === step.key,
              "aria-label": activatingStep === step.key ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.sprintf)(/* translators: %s: feature name */
              (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Enabling %s…', 'performance-optimisation'), step.label) : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.sprintf)(/* translators: %s: feature name */
              (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Enable %s', 'performance-optimisation'), step.label),
              onClick: () => handleStepAction(step),
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Enable', 'performance-optimisation'),
              loadingLabel: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Enabling…', 'performance-optimisation')
            })
          })]
        }, step.key);
      })
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (WelcomePanel);

/***/ },

/***/ "./src/components/common/CheckboxOption.js"
/*!*************************************************!*\
  !*** ./src/components/common/CheckboxOption.js ***!
  \*************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   CheckboxOption: () => (/* binding */ CheckboxOption),
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__);


/**
 * A reusable checkbox option component with optional description and nested settings.
 *  * Improved for Premium Indigo Design System.
 *
 * @param {Object}               props                       Component props.
 * @param {string}               props.label                 The checkbox label.
 * @param {boolean}              props.checked               Whether the checkbox is checked.
 * @param {Function}             props.onChange              Change handler for the checkbox.
 * @param {string}               props.name                  Name attribute for the checkbox.
 * @param {string}               [props.id]                  Optional ID for the checkbox.
 * @param {string}               [props.textareaName]        Optional name for a nested textarea.
 * @param {string}               [props.textareaPlaceholder] Optional placeholder for the textarea.
 * @param {string}               [props.textareaValue]       Value for the nested textarea.
 * @param {Function}             [props.onTextareaChange]    Change handler for the textarea.
 * @param {string}               [props.description]         Optional description text.
 * @param {import('react').Node} [props.children]            Additional child elements.
 * @param {string}               [props.className]           Optional additional class names.
 */

const CheckboxOption = ({
  label,
  checked,
  onChange,
  name,
  id: idProp,
  textareaName,
  textareaPlaceholder,
  textareaValue,
  onTextareaChange,
  description,
  children,
  className = ''
}) => {
  const uid = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useId)();
  const id = idProp ?? uid;
  const descriptionId = description ? `desc-${id}` : undefined;
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsxs)("div", {
    className: `wppo-checkbox-option ${checked ? 'wppo-is-checked' : ''} ${className}`.trim(),
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsxs)("label", {
      htmlFor: id,
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("input", {
        id: id,
        type: "checkbox",
        name: name,
        checked: checked,
        onChange: onChange,
        "aria-describedby": descriptionId
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("span", {
        className: "wppo-option-label-text",
        children: label
      })]
    }), description && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("p", {
      id: descriptionId,
      className: "wppo-option-description",
      children: description
    }), checked && (textareaName || children) && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsxs)("div", {
      className: "wppo-nested-content",
      children: [textareaName && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("div", {
        className: "wppo-field-group",
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("textarea", {
          className: "wppo-text-area-field",
          placeholder: textareaPlaceholder || '',
          "aria-label": textareaPlaceholder || label,
          name: textareaName,
          value: textareaValue,
          onChange: onTextareaChange
        })
      }), children]
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (CheckboxOption);

/***/ },

/***/ "./src/components/common/ConfirmDialog.js"
/*!************************************************!*\
  !*** ./src/components/common/ConfirmDialog.js ***!
  \************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! @fortawesome/react-fontawesome */ "./node_modules/@fortawesome/react-fontawesome/index.es.js");
/* harmony import */ var _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! @fortawesome/free-solid-svg-icons */ "./node_modules/@fortawesome/free-solid-svg-icons/index.mjs");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_3___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__);
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__);





/**
 * A reusable confirmation dialog component for destructive actions.
 *
 * @param {Object}               props                Component props.
 * @param {boolean}              props.isOpen         Whether the dialog is visible.
 * @param {Function}             props.onConfirm      Callback fired on confirm.
 * @param {Function}             props.onCancel       Callback fired on cancel or Escape.
 * @param {string}               props.title          Dialog heading.
 * @param {string}               props.message        Dialog body text.
 * @param {string}               [props.confirmLabel] Label for the confirm button.
 * @param {string}               [props.cancelLabel]  Label for the cancel button.
 * @param {string}               [props.variant]      'warning' | 'danger' — controls confirm button style.
 * @param {import('react').Node} [props.children]     Optional extra content (e.g., a detail list).
 */

const ConfirmDialog = ({
  isOpen,
  onConfirm,
  onCancel,
  title,
  message,
  confirmLabel,
  cancelLabel,
  variant = 'danger',
  children
}) => {
  const dialogRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(null);
  const confirmBtnRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(null);
  const focusableRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)([]);
  const previouslyFocusedRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(null);
  const handleKeyDown = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(e => {
    if (e.key === 'Escape') {
      onCancel();
    }

    // Focus trap.
    if (e.key === 'Tab' && dialogRef.current) {
      const focusable = focusableRef.current;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (e.shiftKey) {
        if (dialogRef.current?.ownerDocument?.activeElement === first) {
          e.preventDefault();
          last.focus();
        }
      } else if (dialogRef.current?.ownerDocument?.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    }
  }, [onCancel]);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    if (isOpen && dialogRef.current) {
      focusableRef.current = Array.from(dialogRef.current.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'));
    } else {
      focusableRef.current = [];
    }
  }, [isOpen]);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    if (isOpen && confirmBtnRef.current) {
      const cancelBtn = dialogRef.current?.querySelector('.wppo-dialog-cancel');
      if (cancelBtn) {
        cancelBtn.focus();
      }
    }
  }, [isOpen]);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    const currentDialog = dialogRef.current;
    const doc = currentDialog?.ownerDocument || document;
    if (isOpen) {
      previouslyFocusedRef.current = doc.activeElement;
      doc.addEventListener('keydown', handleKeyDown);
      doc.body.style.overflow = 'hidden';
    }
    return () => {
      doc.removeEventListener('keydown', handleKeyDown);
      doc.body.style.overflow = '';
    };
  }, [isOpen, handleKeyDown]);

  // Return focus to the element that opened the dialog when it closes.
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    if (isOpen || !previouslyFocusedRef.current) {
      return;
    }
    const previouslyFocused = previouslyFocusedRef.current;
    previouslyFocusedRef.current = null;
    if (previouslyFocused && typeof previouslyFocused.focus === 'function' && previouslyFocused.isConnected) {
      previouslyFocused.focus();
    }
  }, [isOpen]);
  if (!isOpen) {
    return null;
  }
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
    className: "wppo-dialog-overlay",
    onClick: onCancel,
    onKeyDown: e => {
      if (e.target === e.currentTarget && (e.key === 'Enter' || e.key === ' ')) {
        onCancel();
      }
    },
    role: "presentation",
    children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
      className: "wppo-dialog",
      ref: dialogRef,
      role: "dialog",
      "aria-modal": "true",
      "aria-labelledby": "wppo-dialog-title",
      onClick: e => e.stopPropagation(),
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("h3", {
        id: "wppo-dialog-title",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_1__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_2__.faExclamationTriangle
        }), title]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("p", {
        children: message
      }), children, /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
        className: "wppo-dialog-actions",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
          type: "button",
          className: "wppo-button wppo-button--secondary wppo-dialog-cancel",
          onClick: onCancel,
          children: cancelLabel || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Cancel', 'performance-optimisation')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
          type: "button",
          className: `wppo-button ${variant === 'danger' ? 'wppo-button--danger' : 'wppo-button--primary'}`,
          onClick: onConfirm,
          ref: confirmBtnRef,
          children: confirmLabel || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_3__.__)('Confirm', 'performance-optimisation')
        })]
      })]
    })
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (ConfirmDialog);

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
      className: "wppo-mr-8"
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
    role: type === 'error' ? 'alert' : 'status',
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

/***/ "./src/components/common/StatusBadge.js"
/*!**********************************************!*\
  !*** ./src/components/common/StatusBadge.js ***!
  \**********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__);
/**
 * StatusBadge component.
 *
 * Renders a colour-coded pill badge for a metric status value.
 * Supports 'good', 'needs_improvement', and 'poor' variants using
 * --wppo- CSS custom properties defined in the abstracts layer.
 *
 * @since 1.5.0
 */



const StatusBadge = ({
  status
}) => {
  const labelMap = {
    good: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Good', 'performance-optimisation'),
    needs_improvement: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Needs Improvement', 'performance-optimisation'),
    poor: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_0__.__)('Poor', 'performance-optimisation')
  };
  const label = labelMap[status] || status;
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("span", {
    className: `wppo-status-badge wppo-status-badge--${status}`,
    "aria-label": label,
    children: label
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (StatusBadge);

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

/***/ "./src/lib/litespeed.js"
/*!******************************!*\
  !*** ./src/lib/litespeed.js ***!
  \******************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   getEffectiveMode: () => (/* binding */ getEffectiveMode),
/* harmony export */   modeLabel: () => (/* binding */ modeLabel),
/* harmony export */   shouldDisableOptimizer: () => (/* binding */ shouldDisableOptimizer)
/* harmony export */ });
/**
 * LiteSpeed helper — pure JS, no WordPress dependencies.
 *
 * Mirrors the PHP LiteSpeed_Integration::effective_mode() logic for the SPA
 * so the UI can decide ownership without an extra REST call. Imported by
 * FileOptimization.js and Dashboard.js for mode-label rendering (A05/A10).
 *
 * @since NEXT
 */

/**
 * Resolve the effective LiteSpeed mode from config + detection.
 *
 * @param {Object}  opts                 - Options.
 * @param {string}  opts.mode            - Configured mode (auto|wppo|litespeed|standalone).
 * @param {boolean} opts.isLiteSpeed     - Whether LiteSpeed server is detected.
 * @param {boolean} opts.isLscacheActive - Whether LSCache plugin is active.
 * @return {string} Effective mode (wppo|litespeed|standalone).
 */
const getEffectiveMode = ({
  mode = 'auto',
  isLiteSpeed = false,
  isLscacheActive = false
} = {}) => {
  if (!isLiteSpeed) {
    return 'standalone';
  }
  if (mode === 'standalone') {
    return 'standalone';
  }
  if (mode === 'wppo') {
    return 'wppo';
  }
  if (mode === 'litespeed') {
    return 'litespeed';
  }
  // auto
  return isLscacheActive ? 'litespeed' : 'wppo';
};

/**
 * Whether WPPO optimizer should be disabled in the current LiteSpeed mode.
 *
 * @param {Object} opts - Options.
 * @return {boolean} True if optimizer should be disabled.
 */
const shouldDisableOptimizer = (opts = {}) => {
  if (!opts.isLscacheActive) {
    return false;
  }
  return getEffectiveMode(opts) !== 'wppo';
};

/**
 * Human label for a mode value.
 *
 * @param {string} mode - Mode value.
 * @return {string} Human readable label.
 */
const modeLabel = mode => {
  const map = {
    auto: 'Auto',
    wppo: 'WPPO',
    litespeed: 'LiteSpeed Cache',
    standalone: 'Standalone'
  };
  return map[mode] || mode;
};

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

/***/ }

}]);
//# sourceMappingURL=tab-dashboard.js.map?ver=a1f08b5a55967276a4d5