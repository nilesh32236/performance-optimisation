"use strict";
(globalThis["webpackChunkperformance_optimisation"] ||= []).push([["tab-object-cache"],{

/***/ "./src/components/ObjectCache.js"
/*!***************************************!*\
  !*** ./src/components/ObjectCache.js ***!
  \***************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (__WEBPACK_DEFAULT_EXPORT__)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _lib_util__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ../lib/util */ "./src/lib/util.js");
/* harmony import */ var _lib_apiRequest__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ../lib/apiRequest */ "./src/lib/apiRequest.js");
/* harmony import */ var _lib_useNotice__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ../lib/useNotice */ "./src/lib/useNotice.js");
/* harmony import */ var _fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! @fortawesome/react-fontawesome */ "./node_modules/@fortawesome/react-fontawesome/index.es.js");
/* harmony import */ var _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! @fortawesome/free-solid-svg-icons */ "./node_modules/@fortawesome/free-solid-svg-icons/index.mjs");
/* harmony import */ var _common_FeatureHeader__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! ./common/FeatureHeader */ "./src/components/common/FeatureHeader.js");
/* harmony import */ var _common_FeatureCard__WEBPACK_IMPORTED_MODULE_7__ = __webpack_require__(/*! ./common/FeatureCard */ "./src/components/common/FeatureCard.js");
/* harmony import */ var _common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_8__ = __webpack_require__(/*! ./common/LoadingSubmitButton */ "./src/components/common/LoadingSubmitButton.js");
/* harmony import */ var _common_SwitchField__WEBPACK_IMPORTED_MODULE_9__ = __webpack_require__(/*! ./common/SwitchField */ "./src/components/common/SwitchField.js");
/* harmony import */ var _common_NoticeBanner__WEBPACK_IMPORTED_MODULE_10__ = __webpack_require__(/*! ./common/NoticeBanner */ "./src/components/common/NoticeBanner.js");
/* harmony import */ var _common_ConfirmDialog__WEBPACK_IMPORTED_MODULE_11__ = __webpack_require__(/*! ./common/ConfirmDialog */ "./src/components/common/ConfirmDialog.js");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__ = __webpack_require__(/*! @wordpress/i18n */ "@wordpress/i18n");
/* harmony import */ var _wordpress_i18n__WEBPACK_IMPORTED_MODULE_12___default = /*#__PURE__*/__webpack_require__.n(_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__);
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__);














const ObjectCache = ({
  options = {}
}) => {
  const hitRatioLabelId = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useId)();
  const defaultSettings = {
    mode: 'standalone',
    host: '127.0.0.1',
    port: 6379,
    password: '',
    database: 0,
    nodes: '',
    master_name: 'mymaster',
    use_tls: false,
    persistent: false,
    compression: 'none',
    ...options
  };
  const [settings, setSettings] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(defaultSettings);
  const [isLoading, setIsLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [isActionLoading, setIsActionLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [cacheStatus, setCacheStatus] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)({
    enabled: false,
    redis_missing: false,
    foreign_dropin: false,
    redis_reachable: false,
    statusLoaded: false,
    supported_compressors: null
  });
  const [confirmDisable, setConfirmDisable] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const {
    notice,
    notify,
    dismiss
  } = (0,_lib_useNotice__WEBPACK_IMPORTED_MODULE_3__["default"])();
  const fetchStatus = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(async () => {
    try {
      const res = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_2__.apiCall)('object_cache', {
        action: 'status'
      });
      if (res.success) {
        setCacheStatus(prev => ({
          ...res.data,
          statusLoaded: true,
          supported_compressors: res.data.supported_compressors ?? prev.supported_compressors ?? {
            none: true
          }
        }));
      } else {
        setCacheStatus(prev => ({
          ...prev,
          statusLoaded: true,
          supported_compressors: prev.supported_compressors ?? {
            none: true
          }
        }));
      }
    } catch (error) {
      console.error('Error fetching cache status', error);
      setCacheStatus(prev => ({
        ...prev,
        statusLoaded: true,
        supported_compressors: prev.supported_compressors ?? {
          none: true
        }
      }));
      notify({
        type: 'error',
        message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Failed to check cache status.', 'performance-optimisation'),
        durationMs: 5000
      });
    }
  }, [notify]);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    fetchStatus();
  }, [fetchStatus]);
  const handleSubmit = async e => {
    if (e) {
      e.preventDefault();
    }
    setIsLoading(true);
    dismiss();
    try {
      const res = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_2__.apiCall)('update_settings', {
        tab: 'object_cache',
        settings
      });
      if (res.success) {
        notify({
          type: 'success',
          message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Settings saved successfully.', 'performance-optimisation')
        });
      } else {
        notify({
          type: 'error',
          message: res.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Error saving settings.', 'performance-optimisation')
        });
      }
    } catch (err) {
      notify({
        type: 'error',
        message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Error saving settings.', 'performance-optimisation'),
        durationMs: 5000
      });
      console.error(err);
    } finally {
      setIsLoading(false);
    }
  };
  const handleAction = async action => {
    setIsActionLoading(true);
    dismiss();
    try {
      const credentialsRequired = ['enable', 'ping', 'authenticate', 'test-connection'];
      const payload = {
        action,
        ...(credentialsRequired.includes(action) ? settings : {
          mode: settings.mode
        })
      };
      const res = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_2__.apiCall)('object_cache', payload);
      if (!res?.success) {
        notify({
          type: 'error',
          message: res?.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Action failed.', 'performance-optimisation'),
          durationMs: 5000
        });
        return;
      }
      if (['enable', 'disable', 'ping'].includes(action)) {
        await fetchStatus();
      }
      notify({
        type: 'success',
        message: res.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Action successful.', 'performance-optimisation'),
        durationMs: 5000
      });
    } catch (err) {
      console.error('Object cache action failed:', err);
      notify({
        type: 'error',
        message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Action failed.', 'performance-optimisation'),
        durationMs: 5000
      });
    } finally {
      setIsActionLoading(false);
    }
  };
  const hitRatio = (() => {
    if (!cacheStatus.telemetry) {
      return '0.0';
    }
    const hits = Number.parseInt(cacheStatus.telemetry.keyspace_hits ?? '0', 10) || 0;
    const misses = Number.parseInt(cacheStatus.telemetry.keyspace_misses ?? '0', 10) || 0;
    const total = hits + misses;
    return total > 0 ? (hits / total * 100).toFixed(1) : '0.0';
  })();
  const connectionBadge = (() => {
    if (!cacheStatus.statusLoaded) {
      return null;
    }
    if (!cacheStatus.enabled) {
      return {
        level: 'error',
        text: `○ ${(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Disconnected', 'performance-optimisation')}`
      };
    }
    if (cacheStatus.redis_reachable) {
      return {
        level: 'success',
        text: `● ${(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Connected', 'performance-optimisation')}`
      };
    }
    return {
      level: 'warning',
      text: `○ ${(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Unreachable', 'performance-optimisation')}`
    };
  })();
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
    className: "wppo-dashboard-view",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_common_FeatureHeader__WEBPACK_IMPORTED_MODULE_6__["default"], {
      title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Object Cache', 'performance-optimisation'),
      description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Enterprise-grade Redis object caching with Sentinel and Cluster support.', 'performance-optimisation'),
      actions: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("div", {
        className: "wppo-feature-header__actions",
        children: cacheStatus.enabled ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_8__["default"], {
          type: "button",
          className: "wppo-button wppo-button--secondary",
          onClick: () => handleAction('flush'),
          disabled: isActionLoading,
          isLoading: isActionLoading,
          label: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.Fragment, {
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_4__.FontAwesomeIcon, {
              icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_5__.faBroom
            }), ' ', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Flush Cache', 'performance-optimisation')]
          })
        }) : /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_8__["default"], {
          type: "button",
          className: "wppo-button wppo-button--primary",
          onClick: () => handleAction('enable'),
          disabled: isActionLoading || cacheStatus.redis_missing || !cacheStatus.redis_reachable || cacheStatus.foreign_dropin,
          isLoading: isActionLoading,
          label: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.Fragment, {
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_4__.FontAwesomeIcon, {
              icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_5__.faCheckCircle
            }), ' ', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Enable Object Cache', 'performance-optimisation')]
          })
        })
      })
    }), notice && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_common_NoticeBanner__WEBPACK_IMPORTED_MODULE_10__["default"], {
      type: notice.type,
      message: notice.message,
      onDismiss: dismiss
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
      className: "wppo-notices-container",
      children: [cacheStatus.redis_missing && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
        className: "wppo-notice wppo-notice--error",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_4__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_5__.faExclamationCircle
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("strong", {
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Extension Missing', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("p", {
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('The PhpRedis extension is not installed. Native performance will be limited.', 'performance-optimisation')
          })]
        })]
      }), cacheStatus.foreign_dropin && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
        className: "wppo-notice wppo-notice--warning",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_4__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_5__.faExclamationCircle
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("strong", {
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Conflict Detected', 'performance-optimisation')
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("p", {
            children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Another object cache plugin is currently active. Please disable it to avoid site crashes.', 'performance-optimisation')
          })]
        })]
      })]
    }), cacheStatus.telemetry && cacheStatus.enabled && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
      className: "wppo-stats-grid",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
        className: "wppo-stat-item",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("span", {
          className: "wppo-stat-label",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_4__.FontAwesomeIcon, {
            icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_5__.faMemory,
            style: {
              marginRight: '6px'
            }
          }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Memory Usage', 'performance-optimisation')]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("span", {
          className: "wppo-stat-value",
          children: cacheStatus.telemetry?.used_memory_human || '0B'
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("span", {
          className: "wppo-text-muted",
          children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Peak:', 'performance-optimisation'), ' ', cacheStatus.telemetry?.used_memory_peak_human || '0B']
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
        className: "wppo-stat-item",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("span", {
          className: "wppo-stat-label",
          id: hitRatioLabelId,
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_4__.FontAwesomeIcon, {
            icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_5__.faChartBar,
            style: {
              marginRight: '6px'
            }
          }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Hit Ratio', 'performance-optimisation')]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("span", {
          className: "wppo-stat-value",
          children: [hitRatio, "%"]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("div", {
          className: "wppo-progress-bar",
          role: "progressbar",
          "aria-labelledby": hitRatioLabelId,
          "aria-valuemin": "0",
          "aria-valuemax": "100",
          "aria-valuenow": parseFloat(hitRatio),
          "aria-valuetext": `${hitRatio}%`,
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("div", {
            className: "wppo-progress-bar__fill",
            style: {
              width: `${hitRatio}%`
            }
          })
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("span", {
          className: "wppo-text-muted wppo-text-small",
          title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Cache hits vs total requests', 'performance-optimisation'),
          children: [cacheStatus.telemetry?.keyspace_hits || 0, ' ', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('hits', 'performance-optimisation'), " /", ' ', (parseInt(cacheStatus.telemetry?.keyspace_hits || 0, 10) + parseInt(cacheStatus.telemetry?.keyspace_misses || 0, 10)).toLocaleString(), ' ', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('total', 'performance-optimisation')]
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
        className: "wppo-stat-item",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("span", {
          className: "wppo-stat-label",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_4__.FontAwesomeIcon, {
            icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_5__.faUsers,
            style: {
              marginRight: '6px'
            }
          }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Active Clients', 'performance-optimisation')]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("span", {
          className: "wppo-stat-value",
          children: cacheStatus.telemetry?.connected_clients || 0
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("span", {
          className: "wppo-text-muted",
          title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Cumulative connections handled since Redis started', 'performance-optimisation'),
          children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Total Connections:', 'performance-optimisation'), ' ', Number(cacheStatus.telemetry?.total_connections_received || 0).toLocaleString()]
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
        className: "wppo-stat-item",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("span", {
          className: "wppo-stat-label",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_4__.FontAwesomeIcon, {
            icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_5__.faServer,
            style: {
              marginRight: '6px'
            }
          }), (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Redis Version', 'performance-optimisation')]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("span", {
          className: "wppo-stat-value",
          children: cacheStatus.telemetry?.redis_version || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('N/A', 'performance-optimisation')
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("span", {
          className: "wppo-text-muted",
          children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Uptime:', 'performance-optimisation'), ' ', cacheStatus.telemetry?.uptime_in_seconds ? (cacheStatus.telemetry.uptime_in_seconds / 3600).toFixed(1) : '0', "h"]
        })]
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("form", {
      className: "wppo-stacked-cards",
      onSubmit: handleSubmit,
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_7__["default"], {
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Connection Settings', 'performance-optimisation'),
        icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_4__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_5__.faLink
        }),
        actions: connectionBadge ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("span", {
          className: `wppo-status-badge wppo-status-badge--${connectionBadge.level}`,
          style: {
            fontSize: '11px'
          },
          children: connectionBadge.text
        }) : null,
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
          className: "wppo-field-group",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
            className: "wppo-field",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("label", {
              className: "wppo-field-label",
              htmlFor: "mode",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Deployment Mode', 'performance-optimisation')
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("select", {
              className: "wppo-select",
              id: "mode",
              name: "mode",
              value: settings.mode,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_1__.handleChange)(setSettings),
              "aria-describedby": "mode-desc",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("option", {
                value: "standalone",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Standalone (Single Node)', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("option", {
                value: "sentinel",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Redis Sentinel (HA)', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("option", {
                value: "cluster",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Redis Cluster', 'performance-optimisation')
              })]
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("p", {
              id: "mode-desc",
              className: "wppo-text-muted wppo-mt-10 wppo-text-small",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Choose the Redis topology that matches your infrastructure.', 'performance-optimisation')
            })]
          }), settings.mode === 'standalone' ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
            className: "wppo-grid-2-col wppo-mt-24",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "host",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Host', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("input", {
                className: "wppo-input",
                id: "host",
                type: "text",
                name: "host",
                value: settings.host,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_1__.handleChange)(setSettings),
                style: {
                  fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace'
                }
              })]
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "port",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Port', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("input", {
                className: "wppo-input",
                id: "port",
                type: "number",
                name: "port",
                value: settings.port,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_1__.handleChange)(setSettings),
                style: {
                  fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace'
                }
              })]
            })]
          }) : /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
            className: "wppo-field",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("label", {
              className: "wppo-field-label",
              htmlFor: "nodes",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Server Nodes', 'performance-optimisation')
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("textarea", {
              className: "wppo-textarea wppo-textarea--mono",
              id: "nodes",
              name: "nodes",
              rows: "3",
              placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('host:port (one per line)', 'performance-optimisation'),
              value: settings.nodes,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_1__.handleChange)(setSettings)
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("p", {
              className: "wppo-text-muted wppo-text-small wppo-mt-10",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('One host:port per line. Example: 10.0.0.1:6379', 'performance-optimisation')
            })]
          }), settings.mode === 'sentinel' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
            className: "wppo-field",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("label", {
              className: "wppo-field-label",
              htmlFor: "master_name",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Sentinel Master Name', 'performance-optimisation')
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("input", {
              className: "wppo-input",
              id: "master_name",
              type: "text",
              name: "master_name",
              value: settings.master_name,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_1__.handleChange)(setSettings),
              style: {
                fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace'
              }
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("p", {
              className: "wppo-text-muted wppo-text-small wppo-mt-10",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Name of the Redis master as configured in sentinel.conf.', 'performance-optimisation')
            })]
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
            className: "wppo-grid-2-col wppo-mt-24",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "password",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Auth Password', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("input", {
                className: "wppo-input",
                id: "password",
                type: "password",
                name: "password",
                placeholder: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Optional', 'performance-optimisation'),
                value: settings.password,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_1__.handleChange)(setSettings)
              })]
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "database",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Database ID', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("input", {
                className: "wppo-input",
                id: "database",
                type: "number",
                name: "database",
                value: settings.database,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_1__.handleChange)(setSettings),
                style: {
                  fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace'
                }
              })]
            })]
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
            className: "wppo-mt-24 wppo-flex-gap-12",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_8__["default"], {
              type: "button",
              className: "wppo-button wppo-button--secondary",
              onClick: () => handleAction('ping'),
              disabled: isActionLoading,
              isLoading: isActionLoading,
              label: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.Fragment, {
                children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_4__.FontAwesomeIcon, {
                  icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_5__.faNetworkWired
                }), ' ', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Test Connection', 'performance-optimisation')]
              })
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_8__["default"], {
              type: "submit",
              className: "wppo-button wppo-button--primary",
              isLoading: isLoading,
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Save Changes', 'performance-optimisation')
            })]
          })]
        })
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_7__["default"], {
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Enterprise Performance', 'performance-optimisation'),
        icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_4__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_5__.faShieldAlt
        }),
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
          className: "wppo-field-group",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("label", {
              className: "wppo-field-label",
              htmlFor: "compression",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Memory Compression', 'performance-optimisation')
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("select", {
              className: "wppo-select",
              id: "compression",
              name: "compression",
              value: settings.compression,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_1__.handleChange)(setSettings),
              "aria-describedby": "compression-desc",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("option", {
                value: "none",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('None (Fastest)', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("option", {
                value: "lzf",
                disabled: cacheStatus.statusLoaded && !cacheStatus.supported_compressors?.lzf,
                children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('LZF', 'performance-optimisation'), ' ', cacheStatus.statusLoaded && !cacheStatus.supported_compressors?.lzf ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('(Disabled)', 'performance-optimisation') : '']
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("option", {
                value: "zstd",
                disabled: cacheStatus.statusLoaded && !cacheStatus.supported_compressors?.zstd,
                children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('ZSTD', 'performance-optimisation'), ' ',
                // eslint-disable-next-line no-nested-ternary
                !cacheStatus.statusLoaded ? '' : !cacheStatus.supported_compressors?.zstd ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('(Disabled)', 'performance-optimisation') : (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('(Recommended)', 'performance-optimisation')]
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("option", {
                value: "lz4",
                disabled: cacheStatus.statusLoaded && !cacheStatus.supported_compressors?.lz4,
                children: [(0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('LZ4', 'performance-optimisation'), ' ', cacheStatus.statusLoaded && !cacheStatus.supported_compressors?.lz4 ? (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('(Disabled)', 'performance-optimisation') : '']
              })]
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("p", {
              id: "compression-desc",
              className: "wppo-text-muted wppo-mt-12 wppo-text-small",
              children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Reduces memory footprint for enterprise caches.', 'performance-optimisation')
            })]
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_9__["default"], {
            label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Persistent Connections', 'performance-optimisation'),
            description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Keep connections alive between PHP requests.', 'performance-optimisation'),
            name: "persistent",
            checked: settings.persistent,
            onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_1__.handleChange)(setSettings)
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_9__["default"], {
            label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('TLS / SSL Encryption', 'performance-optimisation'),
            description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Encrypt traffic between WordPress and Redis.', 'performance-optimisation'),
            name: "use_tls",
            checked: settings.use_tls,
            onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_1__.handleChange)(setSettings)
          })]
        })
      })]
    }), cacheStatus.enabled && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("div", {
      className: "wppo-feature-card wppo-danger-zone",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("div", {
        className: "wppo-feature-card__header",
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)("h3", {
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_4__.FontAwesomeIcon, {
            icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_5__.faExclamationCircle,
            style: {
              color: 'var(--wppo-danger, #ef4444)'
            }
          }), ' ', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Danger Zone', 'performance-optimisation')]
        })
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("div", {
        className: "wppo-feature-card__body",
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("p", {
          className: "wppo-text-muted wppo-text-small",
          children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Disabling the object cache will remove the drop-in and flush all cached objects. This cannot be undone.', 'performance-optimisation')
        })
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)("div", {
        className: "wppo-feature-card__footer",
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_8__["default"], {
          type: "button",
          className: "wppo-button wppo-button--danger",
          onClick: () => setConfirmDisable(true),
          disabled: isActionLoading,
          isLoading: isActionLoading,
          label: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.Fragment, {
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_4__.FontAwesomeIcon, {
              icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_5__.faTimes
            }), ' ', (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Disable Object Cache', 'performance-optimisation')]
          })
        })
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_13__.jsx)(_common_ConfirmDialog__WEBPACK_IMPORTED_MODULE_11__["default"], {
      isOpen: confirmDisable,
      onConfirm: () => {
        setConfirmDisable(false);
        handleAction('disable');
      },
      onCancel: () => setConfirmDisable(false),
      title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Disable Object Cache?', 'performance-optimisation'),
      message: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('This will remove the object-cache drop-in and clear all cached data. Are you sure?', 'performance-optimisation'),
      confirmLabel: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_12__.__)('Disable', 'performance-optimisation'),
      variant: "danger"
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (ObjectCache);

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
  let nextValue;
  if ('checkbox' === type) {
    nextValue = checked;
  } else if ('number' === type || 'delayJSIdleTimeout' === name) {
    if ('' === value) {
      nextValue = 'delayJSIdleTimeout' === name ? 3000 : '';
    } else {
      const parsed = Number(value);
      if (Number.isNaN(parsed)) {
        nextValue = 'delayJSIdleTimeout' === name ? 3000 : value;
      } else {
        nextValue = parsed;
        if ('delayJSIdleTimeout' === name) {
          if (!Number.isFinite(nextValue) || nextValue <= 0) {
            nextValue = 3000;
          } else {
            nextValue = Math.min(20000, Math.max(500, nextValue));
          }
        }
      }
    }
  } else {
    nextValue = value;
  }
  setSettings(prevState => ({
    ...prevState,
    [name]: nextValue
  }));
};

/***/ }

}]);
//# sourceMappingURL=tab-object-cache.js.map?ver=578b63dcc45e3924bb33