"use strict";
(globalThis["webpackChunkperformance_optimisation"] ||= []).push([["tab-plugin-setting"],{

/***/ "./src/components/PluginSetting.js"
/*!*****************************************!*\
  !*** ./src/components/PluginSetting.js ***!
  \*****************************************/
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
/* harmony import */ var _fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! @fortawesome/react-fontawesome */ "./node_modules/@fortawesome/react-fontawesome/index.es.js");
/* harmony import */ var _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! @fortawesome/free-solid-svg-icons */ "./node_modules/@fortawesome/free-solid-svg-icons/index.mjs");
/* harmony import */ var _common_ConfirmDialog__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! ./common/ConfirmDialog */ "./src/components/common/ConfirmDialog.js");
/* harmony import */ var _common_FeatureHeader__WEBPACK_IMPORTED_MODULE_7__ = __webpack_require__(/*! ./common/FeatureHeader */ "./src/components/common/FeatureHeader.js");
/* harmony import */ var _common_FeatureCard__WEBPACK_IMPORTED_MODULE_8__ = __webpack_require__(/*! ./common/FeatureCard */ "./src/components/common/FeatureCard.js");
/* harmony import */ var _common_NoticeBanner__WEBPACK_IMPORTED_MODULE_9__ = __webpack_require__(/*! ./common/NoticeBanner */ "./src/components/common/NoticeBanner.js");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_10___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__);
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__);












const ALLOWED_IMPORT_KEYS = ['file_optimisation', 'preload_settings', 'image_optimisation', 'database_cleanup', 'object_cache', 'performance_audit', 'core_tweaks', 'cache_settings'];
const validateImportData = data => {
  if (!data || typeof data !== 'object' || Array.isArray(data)) {
    return false;
  }
  const keys = Object.keys(data);
  if (keys.length === 0) {
    return false;
  }
  return keys.every(key => ALLOWED_IMPORT_KEYS.includes(key) && typeof data[key] === 'object' && data[key] !== null && !Array.isArray(data[key]));
};
const PluginSetting = ({
  options
}) => {
  const [selectedFile, setSelectedFile] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [isImporting, setIsImporting] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const {
    notice: importNotice,
    notify: notifyImport,
    dismiss: dismissImport
  } = (0,_lib_useNotice__WEBPACK_IMPORTED_MODULE_2__["default"])();
  const [confirmImport, setConfirmImport] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const fileInputRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(null);
  const cancelledRef = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(false);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    return () => {
      cancelledRef.current = true;
    };
  }, []);

  // Phase 2 — PageSpeed API key state.
  // Security: use boolean flag only, do not expose the actual key to the client.
  const apiKeyConfigured = typeof wppoSettings !== 'undefined' ? wppoSettings.performance_audit?.pagespeedApiKeyConfigured ?? false : false;
  const [newApiKey, setNewApiKey] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)('');
  const [savingApiKey, setSavingApiKey] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const {
    notice: apiKeyNotice,
    notify: notifyApiKey,
    dismiss: dismissApiKey
  } = (0,_lib_useNotice__WEBPACK_IMPORTED_MODULE_2__["default"])();
  const saveApiKey = async () => {
    setSavingApiKey(true);
    dismissApiKey();
    try {
      const currentSettings = wppoSettings?.settings?.performance_audit ?? {};
      const response = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_1__.apiCall)('update_settings', {
        tab: 'performance_audit',
        settings: {
          ...currentSettings,
          pagespeed_api_key: newApiKey
        }
      });
      if (response.success) {
        setNewApiKey('');
        notifyApiKey({
          type: 'success',
          message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('API key saved.', 'performance-optimisation')
        });
      } else {
        notifyApiKey({
          type: 'error',
          message: response.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Failed to save API key.', 'performance-optimisation')
        });
      }
    } catch (err) {
      notifyApiKey({
        type: 'error',
        message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Error saving API key.', 'performance-optimisation')
      });
      console.error('Save API key error:', err);
    } finally {
      setSavingApiKey(false);
    }
  };

  // Activity log state
  const [logEntries, setLogEntries] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)([]);
  const [logLoading, setLogLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [logLoaded, setLogLoaded] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [logPage, setLogPage] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(1);
  const [logTotalPages, setLogTotalPages] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(1);
  const [logError, setLogError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const getTimestamp = () => {
    return new Date().toISOString().replace(/[:T]/g, '-').split('.')[0];
  };
  const loadActivityLog = async (page = 1) => {
    setLogLoading(true);
    setLogError(null);
    try {
      const data = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_1__.fetchRecentActivities)(page);
      if (data?.activities) {
        setLogEntries(data.activities);
        setLogPage(data.current_page || 1);
        setLogTotalPages(data.total_pages || 1);
        setLogLoaded(true);
      }
    } catch (err) {
      setLogError((0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Failed to load activity log.', 'performance-optimisation'));
      console.error('Failed to load activity log:', err);
    } finally {
      setLogLoading(false);
    }
  };
  const exportSettings = () => {
    // Security: redact sensitive API keys from export.
    const safeOptions = JSON.parse(JSON.stringify(options));
    if (safeOptions.performance_audit?.pagespeed_api_key) {
      safeOptions.performance_audit.pagespeed_api_key = 'REDACTED';
    }
    const blob = new Blob([JSON.stringify(safeOptions, null, 2)], {
      type: 'application/json'
    });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `plugin-settings_${getTimestamp()}.json`;
    link.click();
    URL.revokeObjectURL(link.href);
  };
  const handleFileSelection = event => {
    const file = event.target.files[0];
    setSelectedFile(file || null);
    dismissImport();
  };
  const resetFileInput = () => {
    setSelectedFile(null);
    if (fileInputRef.current) {
      fileInputRef.current.value = '';
    }
  };
  const importSettings = () => {
    if (!selectedFile) {
      notifyImport({
        type: 'error',
        message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Please select a file first.', 'performance-optimisation')
      });
      return;
    }
    setIsImporting(true);
    const reader = new FileReader();
    reader.onerror = () => {
      if (cancelledRef.current) {
        return;
      }
      notifyImport({
        type: 'error',
        message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Error reading file', 'performance-optimisation')
      });
      setIsImporting(false);
      resetFileInput();
    };
    reader.onabort = () => {
      if (cancelledRef.current) {
        return;
      }
      notifyImport({
        type: 'error',
        message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Error reading file', 'performance-optimisation')
      });
      setIsImporting(false);
      resetFileInput();
    };
    reader.onload = e => {
      if (cancelledRef.current) {
        return;
      }
      try {
        const fileData = JSON.parse(e.target.result);
        if (!validateImportData(fileData)) {
          notifyImport({
            type: 'error',
            message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Invalid settings file. The file must contain valid plugin settings.', 'performance-optimisation')
          });
          setIsImporting(false);
          resetFileInput();
          return;
        }
        ;(0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_1__.apiCall)('import_settings', {
          action: 'import_settings',
          settings: fileData
        }).then(data => {
          if (cancelledRef.current) {
            return;
          }
          notifyImport({
            type: data.success ? 'success' : 'error',
            message: data.message || (data.success ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('File imported successfully', 'performance-optimisation') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Import failed', 'performance-optimisation'))
          });
          if (data.success) {
            resetFileInput();
          }
        }).catch(() => {
          if (cancelledRef.current) {
            return;
          }
          notifyImport({
            type: 'error',
            message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Error reading file', 'performance-optimisation')
          });
        }).finally(() => {
          if (!cancelledRef.current) {
            setIsImporting(false);
          }
        });
      } catch {
        if (cancelledRef.current) {
          return;
        }
        notifyImport({
          type: 'error',
          message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Invalid file format. Please select a valid JSON file.', 'performance-optimisation')
        });
        setIsImporting(false);
      }
    };
    reader.readAsText(selectedFile);
  };
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
    className: "wppo-dashboard-view",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_common_FeatureHeader__WEBPACK_IMPORTED_MODULE_7__["default"], {
      title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Tools', 'performance-optimisation'),
      description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Manage your plugin configuration, view the full optimisation activity log, and import or export settings.', 'performance-optimisation')
    }), importNotice && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_common_NoticeBanner__WEBPACK_IMPORTED_MODULE_9__["default"], {
      type: importNotice.type,
      message: importNotice.message
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
      className: "wppo-stacked-cards",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_8__["default"], {
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Optimisation Activity Log', 'performance-optimisation'),
        icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_4__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_5__.faHistory
        }),
        footer: logLoaded && logTotalPages > 1 ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
          className: "wppo-log-pagination",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("button", {
            type: "button",
            className: "wppo-button wppo-button--secondary wppo-button--sm",
            disabled: logPage <= 1 || logLoading,
            onClick: () => loadActivityLog(logPage - 1),
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('← Previous', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("span", {
            className: "wppo-log-pagination__info",
            children: ["Page ", logPage, " of ", logTotalPages]
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("button", {
            type: "button",
            className: "wppo-button wppo-button--secondary wppo-button--sm",
            disabled: logPage >= logTotalPages || logLoading,
            onClick: () => loadActivityLog(logPage + 1),
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Next →', 'performance-optimisation')
          })]
        }) : null,
        children: [!logLoaded && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
          className: "wppo-log-trigger",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("p", {
            className: "wppo-text-muted",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('A full timestamped record of every cache clear, image optimisation, database cleanup, and settings change performed by the plugin.', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_3__["default"], {
            type: "button",
            className: "wppo-button wppo-button--secondary",
            onClick: () => loadActivityLog(1),
            isLoading: logLoading,
            loadingLabel: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Loading log…', 'performance-optimisation'),
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_4__.FontAwesomeIcon, {
              icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_5__.faHistory
            }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Load Activity Log', 'performance-optimisation')]
          })]
        }), logError && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
          className: "wppo-notice wppo-notice--error",
          role: "alert",
          "aria-live": "assertive",
          children: [logError, /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("button", {
            type: "button",
            className: "wppo-button wppo-button--secondary wppo-button--sm",
            style: {
              marginLeft: '12px'
            },
            onClick: () => loadActivityLog(logPage),
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Retry', 'performance-optimisation')
          })]
        }), logLoaded && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.Fragment, {
          children: logEntries.length > 0 ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("ul", {
            className: "wppo-activity-list wppo-activity-list--full",
            children: logEntries.map(entry => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("li", {
              children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("div", {
                className: "wppo-activity-text",
                children: entry.activity
              })
            }, entry.id))
          }) : /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("div", {
            className: "wppo-empty-state",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('No activity recorded yet.', 'performance-optimisation')
          })
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_8__["default"], {
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Google PageSpeed API Key', 'performance-optimisation'),
        icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_4__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_5__.faTachometerAlt
        }),
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("p", {
          id: "pagespeed-api-key-desc",
          className: "wppo-text-muted",
          style: {
            marginBottom: '16px'
          },
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Required to run PageSpeed Insights scans. Get a free key from Google Cloud Console.', 'performance-optimisation')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
          className: `wppo-notice wppo-notice--${apiKeyConfigured ? 'success' : 'warning'}`,
          style: {
            marginBottom: '16px'
          },
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_4__.FontAwesomeIcon, {
            icon: apiKeyConfigured ? _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_5__.faCheckCircle : _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_5__.faExclamationCircle,
            style: {
              marginRight: '8px'
            }
          }), apiKeyConfigured ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('API key is configured.', 'performance-optimisation') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('API key is not configured.', 'performance-optimisation')]
        }), apiKeyNotice && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_common_NoticeBanner__WEBPACK_IMPORTED_MODULE_9__["default"], {
          type: apiKeyNotice.type,
          message: apiKeyNotice.message,
          className: "wppo-mb-16"
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
          className: "wppo-field",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("label", {
            className: "wppo-field-label",
            htmlFor: "pagespeed-api-key",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('New API Key', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("input", {
            type: "password",
            id: "pagespeed-api-key",
            className: "wppo-input",
            value: newApiKey,
            onChange: e => setNewApiKey(e.target.value),
            placeholder: apiKeyConfigured ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Leave empty to keep current key', 'performance-optimisation') : 'AIza...',
            autoComplete: "off",
            "aria-describedby": "pagespeed-api-key-desc"
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_3__["default"], {
          className: "wppo-button wppo-button--primary wppo-mt-16",
          onClick: saveApiKey,
          isLoading: savingApiKey,
          label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Save Settings', 'performance-optimisation'),
          loadingLabel: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Saving…', 'performance-optimisation')
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_8__["default"], {
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Export Configuration', 'performance-optimisation'),
        icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_4__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_5__.faFileExport
        }),
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("p", {
          className: "wppo-text-muted",
          style: {
            marginBottom: '24px'
          },
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Download your current plugin settings as a JSON file for backup or migration to another site.', 'performance-optimisation')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_3__["default"], {
          className: "wppo-button wppo-button--primary",
          onClick: exportSettings,
          label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Export Settings', 'performance-optimisation')
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_8__["default"], {
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Import Configuration', 'performance-optimisation'),
        icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_4__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_5__.faFileImport
        }),
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("p", {
          className: "wppo-text-muted",
          id: "import-config-desc",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Upload a previously exported settings file to restore your configuration. This will overwrite all current settings.', 'performance-optimisation')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsxs)("div", {
          className: "wppo-field wppo-mt-24",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("label", {
            className: "wppo-field-label",
            htmlFor: "import-config",
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Select configuration file', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)("input", {
            type: "file",
            id: "import-config",
            accept: "application/json",
            onChange: handleFileSelection,
            ref: fileInputRef,
            className: "wppo-input",
            "aria-describedby": "import-config-desc"
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_3__["default"], {
          className: "wppo-button wppo-button--secondary wppo-mt-24",
          onClick: () => {
            if (selectedFile) {
              setConfirmImport(true);
            }
          },
          disabled: !selectedFile || isImporting,
          isLoading: isImporting,
          label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Import Settings', 'performance-optimisation'),
          loadingLabel: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Importing…', 'performance-optimisation')
        })]
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_11__.jsx)(_common_ConfirmDialog__WEBPACK_IMPORTED_MODULE_6__["default"], {
      isOpen: confirmImport,
      onConfirm: () => {
        setConfirmImport(false);
        importSettings();
      },
      onCancel: () => setConfirmImport(false),
      title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Confirm Import', 'performance-optimisation'),
      message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Importing this file will overwrite all current plugin settings. Continue?', 'performance-optimisation'),
      confirmLabel: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_10__.__)('Confirm', 'performance-optimisation'),
      variant: "warning"
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (PluginSetting);

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
      doc.addEventListener('keydown', handleKeyDown);
      doc.body.style.overflow = 'hidden';
    }
    return () => {
      doc.removeEventListener('keydown', handleKeyDown);
      doc.body.style.overflow = '';
    };
  }, [isOpen, handleKeyDown]);
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
//# sourceMappingURL=tab-plugin-setting.js.map?ver=39f86511cb3702db45a9