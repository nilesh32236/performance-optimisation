/**
 * Performance Monitor Admin JavaScript
 * 
 * @package PerformanceOptimisation
 * @since   2.1.0
 */

(function($) {
    'use strict';

    class PerformanceMonitorAdmin {
        constructor() {
            this.refreshInterval = null;
            this.charts = {};
            this.init();
        }

        init() {
            this.bindEvents();
            this.loadDashboardData();
            this.startAutoRefresh();
        }

        bindEvents() {
            // Optimization buttons
            $('.wppo-action-btn').on('click', (e) => {
                this.handleOptimizationAction(e);
            });

            // Recommendation filters
            $('.wppo-filter-btn').on('click', (e) => {
                this.filterRecommendations(e);
            });

            // Apply recommendation buttons (delegated)
            $(document).on('click', '.wppo-apply-recommendation', (e) => {
                this.applyRecommendation(e);
            });

            // Refresh button
            $(document).on('click', '.wppo-refresh-btn', () => {
                this.loadDashboardData();
            });
        }

        loadDashboardData() {
            $('#wppo-dashboard-loading').show();
            $('#wppo-dashboard').hide();
            $('#wppo-optimizer-loading').show();
            $('#wppo-optimizer-content').hide();

            $.ajax({
                url: wppoMonitor.ajaxurl,
                type: 'POST',
                data: {
                    action: 'wppo_get_dashboard_data',
                    nonce: wppoMonitor.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.updateDashboard(response.data);
                        this.updateOptimizer(response.data);
                    } else {
                        this.showError(response.data.message || wppoMonitor.strings.error);
                    }
                },
                error: () => {
                    this.showError(wppoMonitor.strings.error);
                },
                complete: () => {
                    $('#wppo-dashboard-loading').hide();
                    $('#wppo-dashboard').show();
                    $('#wppo-optimizer-loading').hide();
                    $('#wppo-optimizer-content').show();
                }
            });
        }

        updateDashboard(data) {
            // Update overview metrics
            this.updateOverviewMetrics(data.overview, data.core_metrics);
            
            // Update Core Web Vitals
            this.updateCoreWebVitals(data.core_metrics.core_vitals);
            
            // Update real-time stats
            this.updateRealTimeStats(data.real_time_stats);
            
            // Update charts
            this.updateCharts(data.charts_data);
            
            // Update alerts and bottlenecks
            this.updateAlerts(data.alerts);
            this.updateBottlenecks(data.performance_insights.bottlenecks);
        }

        updateOverviewMetrics(overview, metrics) {
            // Performance score
            const scoreElement = $('#performance-score');
            const score = overview.performance_score;
            scoreElement.text(score).removeClass().addClass('wppo-score-badge');
            
            if (score >= 90) scoreElement.addClass('excellent');
            else if (score >= 80) scoreElement.addClass('good');
            else if (score >= 60) scoreElement.addClass('fair');
            else scoreElement.addClass('poor');

            // Trend indicator
            const trendElement = $('#performance-trend');
            const trendIndicator = trendElement.find('.wppo-trend-indicator');
            const trendText = trendElement.find('.wppo-trend-text');
            
            trendIndicator.removeClass().addClass('wppo-trend-indicator');
            if (overview.trend === 'improving') {
                trendIndicator.addClass('improving').html('↗');
                trendText.text('Improving');
            } else if (overview.trend === 'declining') {
                trendIndicator.addClass('declining').html('↘');
                trendText.text('Declining');
            } else {
                trendIndicator.addClass('stable').html('→');
                trendText.text('Stable');
            }

            // Other metrics
            $('#load-time').text(metrics.page_load_time.toFixed(2));
            $('#cache-hit-rate').text(Math.round(metrics.cache_hit_rate));
            $('#server-response').text(metrics.server_response_time || '--');
        }

        updateCoreWebVitals(vitals) {
            // LCP
            $('#lcp-value').text(vitals.lcp ? vitals.lcp.toFixed(2) + 's' : '--');
            $('#lcp-status').removeClass().addClass('wppo-vital-status ' + vitals.lcp_score);
            
            // FID
            $('#fid-value').text(vitals.fid ? vitals.fid.toFixed(0) + 'ms' : '--');
            $('#fid-status').removeClass().addClass('wppo-vital-status ' + vitals.fid_score);
            
            // CLS
            $('#cls-value').text(vitals.cls ? vitals.cls.toFixed(3) : '--');
            $('#cls-status').removeClass().addClass('wppo-vital-status ' + vitals.cls_score);
        }

        updateRealTimeStats(stats) {
            $('#active-users').text(stats.active_users || '--');
            $('#requests-per-minute').text(stats.requests_per_minute || '--');
            $('#error-rate').text(stats.error_rate ? (stats.error_rate * 100).toFixed(2) + '%' : '--');
            $('#memory-usage').text(stats.memory_usage || '--');
        }

        updateCharts(chartsData) {
            // Load time trend chart
            if (chartsData.performance_trend && chartsData.performance_trend.length > 0) {
                this.createLoadTimeChart(chartsData.performance_trend);
            }
            
            // Cache performance chart
            if (chartsData.cache_performance) {
                this.createCacheChart(chartsData.cache_performance);
            }
        }

        createLoadTimeChart(data) {
            const ctx = document.getElementById('load-time-chart');
            if (!ctx) return;

            if (this.charts.loadTime) {
                this.charts.loadTime.destroy();
            }

            const labels = data.map(item => item.date);
            const loadTimes = data.map(item => item.avg_load_time);

            this.charts.loadTime = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Load Time (s)',
                        data: loadTimes,
                        borderColor: '#007cba',
                        backgroundColor: 'rgba(0, 124, 186, 0.1)',
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Seconds'
                            }
                        }
                    }
                }
            });
        }

        createCacheChart(data) {
            const ctx = document.getElementById('cache-chart');
            if (!ctx) return;

            if (this.charts.cache) {
                this.charts.cache.destroy();
            }

            this.charts.cache = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Cache Hits', 'Cache Misses'],
                    datasets: [{
                        data: [data.hits || 0, data.misses || 0],
                        backgroundColor: ['#46b450', '#dc3232']
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }

        updateAlerts(alerts) {
            const alertsList = $('#alerts-list');
            alertsList.empty();

            if (!alerts || alerts.length === 0) {
                alertsList.html('<p class="wppo-no-alerts">No active alerts</p>');
                return;
            }

            alerts.forEach(alert => {
                const alertHtml = `
                    <div class="wppo-alert wppo-alert-${alert.severity}">
                        <div class="wppo-alert-icon">
                            ${this.getAlertIcon(alert.severity)}
                        </div>
                        <div class="wppo-alert-content">
                            <h4>${alert.type.charAt(0).toUpperCase() + alert.type.slice(1)} Alert</h4>
                            <p>${alert.message}</p>
                            <small>${this.formatTimestamp(alert.timestamp)}</small>
                        </div>
                    </div>
                `;
                alertsList.append(alertHtml);
            });
        }

        updateBottlenecks(bottlenecks) {
            const bottlenecksList = $('#bottlenecks-list');
            bottlenecksList.empty();

            if (!bottlenecks || bottlenecks.length === 0) {
                bottlenecksList.html('<p class="wppo-no-bottlenecks">No bottlenecks detected</p>');
                return;
            }

            bottlenecks.forEach(bottleneck => {
                const bottleneckHtml = `
                    <div class="wppo-bottleneck wppo-bottleneck-${bottleneck.severity}">
                        <div class="wppo-bottleneck-header">
                            <h4>${bottleneck.type.charAt(0).toUpperCase() + bottleneck.type.slice(1)} Bottleneck</h4>
                            <span class="wppo-severity-badge wppo-severity-${bottleneck.severity}">
                                ${bottleneck.severity.toUpperCase()}
                            </span>
                        </div>
                        <p class="wppo-bottleneck-description">${bottleneck.description}</p>
                        <p class="wppo-bottleneck-recommendation">
                            <strong>Recommendation:</strong> ${bottleneck.recommendation}
                        </p>
                    </div>
                `;
                bottlenecksList.append(bottleneckHtml);
            });
        }

        updateOptimizer(data) {
            // Update analysis summary
            this.updateAnalysisSummary(data);
            
            // Update recommendations
            this.updateRecommendations(data.recommendations);
            
            // Update estimated improvements
            this.updateEstimatedImprovements(data.performance_insights.optimization_opportunities);
        }

        updateAnalysisSummary(data) {
            const score = data.overview.performance_score;
            $('#overall-score').text(score);
            
            let description = '';
            if (score >= 90) description = 'Excellent performance!';
            else if (score >= 80) description = 'Good performance with room for improvement';
            else if (score >= 60) description = 'Fair performance, optimization recommended';
            else description = 'Poor performance, immediate action needed';
            
            $('#score-description').text(description);
            
            // Count recommendations by priority
            const criticalCount = data.recommendations.filter(r => r.type === 'critical').length;
            $('#priority-issues').text(criticalCount);
            
            // Calculate optimization potential
            const potential = Math.min(100 - score, 40); // Max 40 point improvement
            $('#optimization-potential').text(`+${potential} points possible`);
        }

        updateRecommendations(recommendations) {
            const recommendationsList = $('#recommendations-list');
            recommendationsList.empty();

            if (!recommendations || recommendations.length === 0) {
                recommendationsList.html('<p class="wppo-no-recommendations">No recommendations available</p>');
                return;
            }

            recommendations.forEach((rec, index) => {
                const recHtml = `
                    <div class="wppo-recommendation wppo-rec-${rec.type}" data-category="${rec.category}">
                        <div class="wppo-rec-header">
                            <h3>${rec.title}</h3>
                            <div class="wppo-rec-badges">
                                <span class="wppo-impact-badge wppo-impact-${rec.impact}">${rec.impact} impact</span>
                                <span class="wppo-effort-badge wppo-effort-${rec.effort}">${rec.effort} effort</span>
                            </div>
                        </div>
                        <p class="wppo-rec-description">${rec.description}</p>
                        <div class="wppo-rec-improvement">
                            <strong>Expected improvement:</strong> ${rec.estimated_improvement}
                        </div>
                        <div class="wppo-rec-actions">
                            <button class="button button-primary wppo-apply-recommendation" 
                                    data-recommendation='${JSON.stringify(rec)}'>
                                Apply Optimization
                            </button>
                            <button class="button wppo-learn-more" data-rec-id="${index}">
                                Learn More
                            </button>
                        </div>
                    </div>
                `;
                recommendationsList.append(recHtml);
            });
        }

        updateEstimatedImprovements(opportunities) {
            const improvementsGrid = $('#improvements-grid');
            improvementsGrid.empty();

            if (!opportunities || opportunities.length === 0) {
                improvementsGrid.html('<p class="wppo-no-improvements">No improvement estimates available</p>');
                return;
            }

            opportunities.forEach(opp => {
                const impHtml = `
                    <div class="wppo-improvement-card">
                        <h4>${opp.type.charAt(0).toUpperCase() + opp.type.slice(1)} Optimization</h4>
                        <div class="wppo-improvement-impact wppo-impact-${opp.impact}">
                            ${opp.impact.toUpperCase()} IMPACT
                        </div>
                        <p>${opp.description}</p>
                        <div class="wppo-improvement-benefit">
                            ${opp.potential_improvement}
                        </div>
                    </div>
                `;
                improvementsGrid.append(impHtml);
            });
        }

        handleOptimizationAction(e) {
            e.preventDefault();
            const button = $(e.currentTarget);
            const actionType = button.attr('id').replace('optimize-', '');
            
            if (!confirm(wppoMonitor.strings.confirm)) {
                return;
            }

            button.prop('disabled', true).text('Optimizing...');

            const optimizationData = {
                category: actionType,
                title: `Quick ${actionType} optimization`,
                type: 'info'
            };

            this.applyOptimizationRequest(actionType, optimizationData, button);
        }

        applyRecommendation(e) {
            e.preventDefault();
            const button = $(e.currentTarget);
            const recommendation = JSON.parse(button.attr('data-recommendation'));
            
            if (!confirm(`Apply ${recommendation.title}?`)) {
                return;
            }

            button.prop('disabled', true).text('Applying...');
            this.applyOptimizationRequest(recommendation.category, recommendation, button);
        }

        applyOptimizationRequest(type, data, button) {
            $.ajax({
                url: wppoMonitor.ajaxurl,
                type: 'POST',
                data: {
                    action: 'wppo_apply_optimization',
                    nonce: wppoMonitor.nonce,
                    optimization_type: type,
                    optimization_data: JSON.stringify(data)
                },
                success: (response) => {
                    if (response.success) {
                        this.showSuccess(wppoMonitor.strings.success);
                        // Refresh data after optimization
                        setTimeout(() => {
                            this.loadDashboardData();
                        }, 2000);
                    } else {
                        this.showError(response.data.message || 'Optimization failed');
                    }
                },
                error: () => {
                    this.showError('Network error occurred');
                },
                complete: () => {
                    button.prop('disabled', false);
                    // Reset button text based on context
                    if (button.hasClass('wppo-action-btn')) {
                        button.find('.wppo-action-text').text(button.find('.wppo-action-text').text().replace('Optimizing...', ''));
                    } else {
                        button.text('Apply Optimization');
                    }
                }
            });
        }

        filterRecommendations(e) {
            e.preventDefault();
            const button = $(e.currentTarget);
            const filter = button.data('filter');
            
            $('.wppo-filter-btn').removeClass('active');
            button.addClass('active');
            
            const recommendations = $('.wppo-recommendation');
            
            if (filter === 'all') {
                recommendations.show();
            } else {
                recommendations.hide();
                $(`.wppo-recommendation[data-category="${filter}"], .wppo-recommendation.wppo-rec-${filter}`).show();
            }
        }

        startAutoRefresh() {
            if (wppoMonitor.refreshInterval > 0) {
                this.refreshInterval = setInterval(() => {
                    this.loadDashboardData();
                }, wppoMonitor.refreshInterval);
            }
        }

        stopAutoRefresh() {
            if (this.refreshInterval) {
                clearInterval(this.refreshInterval);
                this.refreshInterval = null;
            }
        }

        showError(message) {
            this.showNotice(message, 'error');
        }

        showSuccess(message) {
            this.showNotice(message, 'success');
        }

        showNotice(message, type) {
            const notice = $(`
                <div class="notice notice-${type} is-dismissible wppo-notice">
                    <p>${message}</p>
                </div>
            `);
            
            $('.wrap').prepend(notice);
            
            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                notice.fadeOut(() => notice.remove());
            }, 5000);
        }

        getAlertIcon(severity) {
            switch (severity) {
                case 'critical': return '🚨';
                case 'warning': return '⚠️';
                case 'info': return 'ℹ️';
                default: return '📊';
            }
        }

        formatTimestamp(timestamp) {
            const date = new Date(timestamp * 1000);
            return date.toLocaleString();
        }
    }

    // Initialize when document is ready
    $(document).ready(() => {
        // Only initialize on monitor pages
        if ($('.wppo-monitor-page, .wppo-optimizer-page').length > 0) {
            new PerformanceMonitorAdmin();
        }
    });

    // Load Chart.js if not already loaded
    if (typeof Chart === 'undefined') {
        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/chart.js';
        document.head.appendChild(script);
    }

})(jQuery);
