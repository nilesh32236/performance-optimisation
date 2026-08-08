"use strict";
(globalThis["webpackChunkperformance_optimisation"] ||= []).push([["tab-image-optimization"],{

/***/ "./src/components/ImageOptimization.js"
/*!*********************************************!*\
  !*** ./src/components/ImageOptimization.js ***!
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













const CLIENT_SIDE_MIME_OPTIONS = [{
  value: 'image/jpeg',
  label: 'JPEG'
}, {
  value: 'image/png',
  label: 'PNG'
}, {
  value: 'image/gif',
  label: 'GIF'
}, {
  value: 'image/webp',
  label: 'WebP'
}, {
  value: 'image/avif',
  label: 'AVIF'
}, {
  value: 'image/heic',
  label: 'HEIC'
}, {
  value: 'image/heif',
  label: 'HEIF'
}];
const DEFAULT_CLIENT_SIDE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
const ImageOptimization = ({
  options = {}
}) => {
  const defaultSettings = {
    lazyLoadImages: false,
    lazyLoadNative: false,
    wrapInPicture: true,
    excludeFirstImages: 0,
    excludeImages: '',
    lazyLoadVideos: false,
    enableVideoPlaceholder: false,
    excludeVideos: '',
    convertImg: false,
    conversionFormat: 'webp',
    excludeConvertImages: '',
    preloadFrontPageImages: false,
    preloadFrontPageImagesUrls: '',
    preloadPostTypeImage: false,
    selectedPostType: [],
    availablePostTypes: [],
    excludePostTypeImgUrl: '',
    maxWidthImgSize: 0,
    excludeSize: '',
    autoPreloadLCP: false,
    prioritizeLCPImages: false,
    clientSideMimeTypeOverride: false,
    clientSideMimeTypes: DEFAULT_CLIENT_SIDE_MIME_TYPES,
    ...options,
    placeholderType: options.placeholderType || (options.replacePlaceholderWithSVG ? 'svg' : 'none')
  };
  const [settings, setSettings] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(defaultSettings);
  const [isLoading, setIsLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const {
    notice,
    notify,
    dismiss
  } = (0,_lib_useNotice__WEBPACK_IMPORTED_MODULE_4__["default"])();
  const togglePostType = type => {
    setSettings(prev => {
      const newSelected = prev.selectedPostType.includes(type) ? prev.selectedPostType.filter(t => t !== type) : [...prev.selectedPostType, type];
      return {
        ...prev,
        selectedPostType: newSelected
      };
    });
  };
  const toggleClientSideMimeTypeOverride = event => {
    const enabled = event.target.checked;
    setSettings(prev => {
      const hasSelection = Array.isArray(prev.clientSideMimeTypes) && prev.clientSideMimeTypes.length > 0;
      return {
        ...prev,
        clientSideMimeTypeOverride: enabled,
        clientSideMimeTypes: enabled && !hasSelection ? DEFAULT_CLIENT_SIDE_MIME_TYPES : prev.clientSideMimeTypes
      };
    });
  };
  const toggleClientSideMimeType = mime => {
    setSettings(prev => {
      const current = Array.isArray(prev.clientSideMimeTypes) ? prev.clientSideMimeTypes : [];
      const next = current.includes(mime) ? current.filter(m => m !== mime) : [...current, mime];
      return {
        ...prev,
        clientSideMimeTypes: next
      };
    });
  };
  const onSubmit = async e => {
    if (e) {
      e.preventDefault();
    }
    setIsLoading(true);
    try {
      const res = await (0,_lib_apiRequest__WEBPACK_IMPORTED_MODULE_3__.apiCall)('update_settings', {
        tab: 'image_optimisation',
        settings
      });
      if (res.success) {
        notify({
          type: 'success',
          message: res.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Settings saved successfully.', 'performance-optimisation'),
          durationMs: 5000
        });
      } else {
        notify({
          type: 'error',
          message: res.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Error saving settings.', 'performance-optimisation'),
          durationMs: 5000
        });
      }
    } catch (error) {
      notify({
        type: 'error',
        message: error.message || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Error saving settings.', 'performance-optimisation'),
        durationMs: 5000
      });
    } finally {
      setIsLoading(false);
    }
  };
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
    className: "wppo-dashboard-view",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_FeatureHeader__WEBPACK_IMPORTED_MODULE_9__["default"], {
      title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Image Optimisation', 'performance-optimisation'),
      description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Optimize media delivery with advanced lazy loading, next-gen formats, and preloading rules.', 'performance-optimisation'),
      actions: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_LoadingSubmitButton__WEBPACK_IMPORTED_MODULE_5__["default"], {
        className: "wppo-button wppo-button--primary",
        isLoading: isLoading,
        onClick: onSubmit,
        label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Save Settings', 'performance-optimisation')
      })
    }), notice && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_NoticeBanner__WEBPACK_IMPORTED_MODULE_11__["default"], {
      type: notice.type,
      message: notice.message,
      onDismiss: dismiss
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
      className: "wppo-stacked-cards",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_10__["default"], {
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Lazy Loading', 'performance-optimisation'),
        icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_7__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_8__.faEye
        }),
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
          className: "wppo-field-group",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_6__["default"], {
            label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Enable Lazy Load', 'performance-optimisation'),
            description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Images below the fold are loaded only when the user scrolls near them. Reduces initial page weight and improves Largest Contentful Paint (LCP) for above-the-fold content.', 'performance-optimisation'),
            name: "lazyLoadImages",
            checked: settings.lazyLoadImages,
            onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
          }), settings.lazyLoadImages && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
            className: "wppo-field-nest",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
              className: "wppo-field",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "excludeFirstImages",
                children: "Exclude First X Images"
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("input", {
                className: "wppo-input",
                id: "excludeFirstImages",
                type: "number",
                name: "excludeFirstImages",
                value: settings.excludeFirstImages,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings),
                "aria-describedby": "excludeFirstImages-desc"
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("p", {
                id: "excludeFirstImages-desc",
                className: "wppo-text-muted wppo-mt-10 wppo-text-small",
                children: "Skip lazy loading for the first N images on the page. Set to 1\u20133 to ensure your hero/banner image loads immediately without waiting for scroll."
              })]
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_6__["default"], {
              label: wppoSettings?.translations?.lazyLoadNative || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Use Native Lazy Loading', 'performance-optimisation'),
              description: wppoSettings?.translations?.lazyLoadNativeDesc || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Use the browser\'s native loading="lazy" attribute instead of JavaScript-based IntersectionObserver. Supported in modern browsers and reduces JS overhead.', 'performance-optimisation'),
              name: "lazyLoadNative",
              checked: settings.lazyLoadNative,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
              className: "wppo-field",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "placeholderType",
                children: wppoSettings?.translations?.placeholderType || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Placeholder Type', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("select", {
                className: "wppo-select",
                id: "placeholderType",
                name: "placeholderType",
                value: settings.placeholderType,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings),
                children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("option", {
                  value: "none",
                  children: wppoSettings?.translations?.placeholderNone || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('None', 'performance-optimisation')
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("option", {
                  value: "svg",
                  children: wppoSettings?.translations?.placeholderSvg || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('SVG Placeholder (Lightweight)', 'performance-optimisation')
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("option", {
                  value: "dominant_color",
                  children: wppoSettings?.translations?.placeholderDominantColor || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Dominant Color (Extracted from Image)', 'performance-optimisation')
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("option", {
                  value: "lqip",
                  children: wppoSettings?.translations?.placeholderLqip || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('LQIP (Blur Preview)', 'performance-optimisation')
                })]
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("p", {
                className: "wppo-text-muted wppo-mt-10 wppo-text-small",
                children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("strong", {
                  children: "None:"
                }), ' ', wppoSettings?.translations?.placeholderNoneDesc || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('The src attribute is removed until the image is in view.', 'performance-optimisation'), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("br", {}), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("strong", {
                  children: [wppoSettings?.translations?.placeholderSvgLabel || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('SVG', 'performance-optimisation'), ":"]
                }), ' ', wppoSettings?.translations?.placeholderSvgDesc || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Lightweight inline SVG while the real image loads. Prevents layout shift.', 'performance-optimisation'), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("br", {}), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("strong", {
                  children: [wppoSettings?.translations?.placeholderDominantColorLabel || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Dominant Color', 'performance-optimisation'), ":"]
                }), ' ', wppoSettings?.translations?.placeholderDominantColorDesc || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Extracted during image conversion. Smooth background-color fade transition.', 'performance-optimisation'), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("br", {}), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("strong", {
                  children: [wppoSettings?.translations?.placeholderLqipLabel || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('LQIP', 'performance-optimisation'), ":"]
                }), ' ', wppoSettings?.translations?.placeholderLqipDesc || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('20×20 blurred preview. Images must be re-optimized for LQIP to take effect.', 'performance-optimisation')]
              })]
            })]
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_6__["default"], {
            label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Wrap in Picture Tag', 'performance-optimisation'),
            description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Wrap <img> elements in a <picture> element to enable serving next-gen formats (WebP/AVIF) with a fallback for older browsers. Required for format conversion to work.', 'performance-optimisation'),
            name: "wrapInPicture",
            checked: settings.wrapInPicture,
            onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
          })]
        })
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_10__["default"], {
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Video & Media', 'performance-optimisation'),
        icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_7__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_8__.faMagic
        }),
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
          className: "wppo-field-group",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_6__["default"], {
            label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Video Lazy Loading', 'performance-optimisation'),
            description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Defer loading of <iframe> and <video> embeds until they enter the viewport. Significantly reduces initial page load time for pages with embedded YouTube, Vimeo, or other media.', 'performance-optimisation'),
            name: "lazyLoadVideos",
            checked: settings.lazyLoadVideos,
            onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
          }), settings.lazyLoadVideos && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("div", {
            className: "wppo-field-nest",
            children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_6__["default"], {
              label: wppoSettings?.translations?.videoPlaceholder || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Video Placeholder', 'performance-optimisation'),
              description: wppoSettings?.translations?.videoPlaceholderDesc || (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Replace YouTube embeds with lightweight thumbnail previews. The actual video player loads only when the user clicks the play button, saving up to 800KB per embed.', 'performance-optimisation'),
              name: "enableVideoPlaceholder",
              checked: settings.enableVideoPlaceholder,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
            })
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
            className: "wppo-field",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("label", {
              className: "wppo-field-label",
              htmlFor: "excludeVideos",
              children: "Exclude from Video Lazy Load"
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("textarea", {
              className: "wppo-textarea",
              id: "excludeVideos",
              name: "excludeVideos",
              rows: "3",
              placeholder: "Class names or partial URLs (one per line)",
              value: settings.excludeVideos,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings),
              "aria-describedby": "excludeVideos-desc"
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("p", {
              id: "excludeVideos-desc",
              className: "wppo-text-muted wppo-mt-10 wppo-text-small",
              children: "Enter CSS class names or partial URLs of embeds that should always load immediately."
            })]
          })]
        })
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_10__["default"], {
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Next-Gen Conversion', 'performance-optimisation'),
        icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_7__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_8__.faMagic
        }),
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
          className: "wppo-field-group",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_6__["default"], {
            label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Auto Convert Formats', 'performance-optimisation'),
            description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Automatically convert uploaded JPEG/PNG images to modern formats (WebP or AVIF). Modern formats are 25–50 percent smaller than JPEG at the same quality, directly improving page speed scores.', 'performance-optimisation'),
            name: "convertImg",
            checked: settings.convertImg,
            onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
          }), settings.convertImg && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
            className: "wppo-field-nest",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
              className: "wppo-field",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "conversionFormat",
                children: "Target Format"
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("select", {
                className: "wppo-select",
                id: "conversionFormat",
                name: "conversionFormat",
                value: settings.conversionFormat,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings),
                children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("option", {
                  value: "webp",
                  children: "WebP (Standard \u2014 95%+ browser support)"
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("option", {
                  value: "avif",
                  children: "AVIF (Maximum Compression \u2014 newer browsers only)"
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("option", {
                  value: "both",
                  children: "Both (Best Compatibility \u2014 serves AVIF where supported, WebP as fallback)"
                })]
              })]
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
              className: "wppo-field wppo-field--spaced",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "excludeConvertImages",
                children: "Exclude from Conversion"
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("textarea", {
                className: "wppo-textarea",
                id: "excludeConvertImages",
                name: "excludeConvertImages",
                rows: "2",
                placeholder: "Partial URLs (one per line)",
                value: settings.excludeConvertImages,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings),
                "aria-describedby": "excludeConvertImages-desc"
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("p", {
                id: "excludeConvertImages-desc",
                className: "wppo-text-muted wppo-mt-10 wppo-text-small",
                children: "Images matching these partial URLs will keep their original format. Useful for logos or images where exact color accuracy matters."
              })]
            })]
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
            className: "wppo-field wppo-field--spaced",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_6__["default"], {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Override Client-Side MIME Types', 'performance-optimisation'),
              description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Control which image formats WordPress 7.1+ client-side media processing handles in the browser. Disable AVIF to avoid duplicating this plugin’s AVIF output, or add HEIC/HEIF for direct browser conversion. Only applies on WordPress 7.1+; older versions are unaffected.', 'performance-optimisation'),
              name: "clientSideMimeTypeOverride",
              checked: settings.clientSideMimeTypeOverride,
              onChange: toggleClientSideMimeTypeOverride
            }), settings.clientSideMimeTypeOverride && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
              className: "wppo-mt-12",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("span", {
                className: "wppo-field-label",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Formats to process in the browser', 'performance-optimisation')
              }), (() => {
                const mimeList = Array.isArray(settings.clientSideMimeTypes) ? settings.clientSideMimeTypes : [];
                return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("div", {
                  className: "wppo-post-types-grid--chips",
                  children: CLIENT_SIDE_MIME_OPTIONS.map(option => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("label", {
                    htmlFor: `client-mime-${option.value}`,
                    className: `wppo-post-type-chip ${mimeList.includes(option.value) ? 'wppo-post-type-chip--active' : ''}`,
                    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("input", {
                      type: "checkbox",
                      id: `client-mime-${option.value}`,
                      className: "screen-reader-text",
                      checked: mimeList.includes(option.value),
                      onChange: () => toggleClientSideMimeType(option.value)
                    }), option.label]
                  }, option.value))
                });
              })(), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("p", {
                className: "wppo-text-muted wppo-mt-10 wppo-text-small",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Unchecking a format makes the browser skip it during upload, falling back to server-side processing. Unchecking every format disables browser-side processing. Formats core cannot process are ignored.', 'performance-optimisation')
              })]
            })]
          })]
        })
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_10__["default"], {
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Responsive Limits', 'performance-optimisation'),
        icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_7__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_8__.faMagic
        }),
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
          className: "wppo-field-group",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
            className: "wppo-field",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("label", {
              className: "wppo-field-label",
              htmlFor: "maxWidthImgSize",
              children: "Max Image Width (px)"
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("input", {
              className: "wppo-input",
              id: "maxWidthImgSize",
              type: "number",
              name: "maxWidthImgSize",
              value: settings.maxWidthImgSize,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings),
              "aria-describedby": "maxWidthImgSize-desc"
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("p", {
              id: "maxWidthImgSize-desc",
              className: "wppo-text-muted wppo-mt-10 wppo-text-small",
              children: ["Images wider than this value will have a", ' ', /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("code", {
                children: "max-width"
              }), " style applied. Set to", ' ', /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("code", {
                children: "0"
              }), " to disable. Useful for preventing oversized images from breaking layouts on small screens."]
            })]
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
            className: "wppo-field",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("label", {
              className: "wppo-field-label",
              htmlFor: "excludeSize",
              children: "Exclude Classes from Max Width"
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("input", {
              className: "wppo-input",
              id: "excludeSize",
              type: "text",
              name: "excludeSize",
              placeholder: "e.g. 300, 600, 1200",
              value: settings.excludeSize,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings),
              "aria-describedby": "excludeSize-desc"
            }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("p", {
              id: "excludeSize-desc",
              className: "wppo-text-muted wppo-mt-10 wppo-text-small",
              children: "Comma-separated image width values (pixels). Images with these widths in srcset will be skipped."
            })]
          })]
        })
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_FeatureCard__WEBPACK_IMPORTED_MODULE_10__["default"], {
        title: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Advanced Preloading', 'performance-optimisation'),
        icon: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_fortawesome_react_fontawesome__WEBPACK_IMPORTED_MODULE_7__.FontAwesomeIcon, {
          icon: _fortawesome_free_solid_svg_icons__WEBPACK_IMPORTED_MODULE_8__.faCloudUploadAlt
        }),
        children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
          className: "wppo-stacked-cards",
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("div", {
            children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_6__["default"], {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Auto-preload LCP Image', 'performance-optimisation'),
              description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Automatically detect and preload the Largest Contentful Paint (LCP) image from PageSpeed scan data. Requires a configured PageSpeed API key. Falls back to featured image when no PageSpeed data is available.', 'performance-optimisation'),
              name: "autoPreloadLCP",
              checked: settings.autoPreloadLCP,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
            })
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("div", {
            children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_6__["default"], {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Prioritize LCP Images in Final HTML', 'performance-optimisation'),
              description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Remove loading="lazy" from the first N images and set fetchpriority="high" on the detected LCP image in the finalized page HTML. Requires WordPress 6.9+ for full effect; falls back gracefully on older versions.', 'performance-optimisation'),
              name: "prioritizeLCPImages",
              checked: settings.prioritizeLCPImages,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
            })
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_6__["default"], {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Preload Front Page Images', 'performance-optimisation'),
              description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Inject <link rel="preload"> hints for critical images on your homepage. Tells the browser to fetch these images at the highest priority, improving LCP scores for your most visited page.', 'performance-optimisation'),
              name: "preloadFrontPageImages",
              checked: settings.preloadFrontPageImages,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
            }), settings.preloadFrontPageImages && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
              className: "wppo-field wppo-mt-12",
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("label", {
                className: "wppo-field-label",
                htmlFor: "preloadFrontPageImagesUrls",
                children: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Frontpage Image URLs to Preload', 'performance-optimisation')
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("textarea", {
                className: "wppo-textarea",
                id: "preloadFrontPageImagesUrls",
                name: "preloadFrontPageImagesUrls",
                rows: "3",
                placeholder: "/wp-content/uploads/hero.jpg",
                value: settings.preloadFrontPageImagesUrls,
                onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings),
                "aria-describedby": "preloadFrontPageImagesUrls-desc"
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("p", {
                id: "preloadFrontPageImagesUrls-desc",
                className: "wppo-text-muted wppo-mt-10 wppo-text-small",
                children: "One URL per line. Only add above-the-fold images \u2014 preloading too many images can hurt performance."
              })]
            })]
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)(_common_SwitchField__WEBPACK_IMPORTED_MODULE_6__["default"], {
              label: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Preload Featured Images', 'performance-optimisation'),
              description: (0,_wordpress_i18n__WEBPACK_IMPORTED_MODULE_1__.__)('Automatically add preload hints for the featured image of posts and pages. Select which post types to apply this to below. Improves LCP for archive and single post pages.', 'performance-optimisation'),
              name: "preloadPostTypeImage",
              checked: settings.preloadPostTypeImage,
              onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
            }), settings.preloadPostTypeImage && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.Fragment, {
              children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("div", {
                className: "wppo-post-types-grid--chips",
                children: settings.availablePostTypes.map(type => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("label", {
                  htmlFor: `type-${type}`,
                  className: `wppo-post-type-chip ${settings.selectedPostType.includes(type) ? 'wppo-post-type-chip--active' : ''}`,
                  children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("input", {
                    type: "checkbox",
                    id: `type-${type}`,
                    className: "screen-reader-text",
                    checked: settings.selectedPostType.includes(type),
                    onChange: () => togglePostType(type)
                  }), type]
                }, type))
              }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsxs)("div", {
                className: "wppo-field wppo-field--spaced",
                children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("label", {
                  className: "wppo-field-label",
                  htmlFor: "excludePostTypeImgUrl",
                  children: "Exclude URLs from Preload"
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_12__.jsx)("textarea", {
                  className: "wppo-textarea",
                  id: "excludePostTypeImgUrl",
                  name: "excludePostTypeImgUrl",
                  rows: "2",
                  placeholder: "Partial URLs (one per line)",
                  value: settings.excludePostTypeImgUrl,
                  onChange: (0,_lib_util__WEBPACK_IMPORTED_MODULE_2__.handleChange)(setSettings)
                })]
              })]
            })]
          })]
        })
      })]
    })]
  });
};
/* harmony default export */ const __WEBPACK_DEFAULT_EXPORT__ = (ImageOptimization);

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
//# sourceMappingURL=tab-image-optimization.js.map?ver=83ac378f0f06b01d747a