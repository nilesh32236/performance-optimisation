// src/components/ActivityLog/ActivityLog.js

import React, { useState, useEffect, useCallback } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faHistory, faSpinner } from '@fortawesome/free-solid-svg-icons';

const ActivityLog = ({
    translations,
    apiUrl,
    nonce,
    // isLoading, // This component manages its own loading for activities
    // setIsLoading // This component manages its own loading for activities
}) => {
    const [activities, setActivities] = useState([]);
    const [isFetching, setIsFetching] = useState(true); // Specific loading state for activities
    const [currentPage, setCurrentPage] = useState(1);
    const [totalPages, setTotalPages] = useState(1);
    const [error, setError] = useState('');
    const perPage = 15; // Or make this configurable

    const fetchActivities = useCallback(async (pageToFetch = 1, loadMore = false) => {
        setIsFetching(true);
        setError('');
        try {
            const response = await fetch(`${apiUrl}recent-activities`, {
                method: 'POST', // Using POST as defined in Rest.php, can be GET if params are URL-encoded
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': nonce,
                },
                body: JSON.stringify({ page: pageToFetch, per_page: perPage }),
            });

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({})); // Try to parse error, default to empty object
                throw new Error(errorData.message || `HTTP error! status: ${response.status}`);
            }

            const result = await response.json();

            if (result.success && result.data) {
                setActivities(prevActivities =>
                    loadMore ? [...prevActivities, ...result.data.activities] : result.data.activities
                );
                setCurrentPage(result.data.current_page);
                setTotalPages(result.data.total_pages);
            } else {
                throw new Error(result.data?.message || result.message || translations.failedFetchActivities || 'Failed to fetch activities.');
            }
        } catch (err) {
            console.error("Error fetching activities:", err);
            setError(err.message);
            // toast?.error(err.message);
        } finally {
            setIsFetching(false);
        }
    }, [apiUrl, nonce, perPage, translations]); // translations added if error messages use it

    useEffect(() => {
        fetchActivities(1, false); // Initial fetch for page 1
    }, [fetchActivities]);

    const handleLoadMore = () => {
        if (currentPage < totalPages && !isFetching) {
            fetchActivities(currentPage + 1, true);
        }
    };

    return (
        <div className="wppo-settings-form wppo-recent-activities-container"> {/* Changed class for better distinction */}
            <h2 className="wppo-section-title">
                <FontAwesomeIcon icon={faHistory} style={{ marginRight: '10px' }} />
                {translations.activityLog || 'Activity Log'}
            </h2>
            <p className="wppo-section-description">
                {translations.activityLogDesc || 'View recent actions and events related to the Performance Optimisation plugin.'}
            </p>

            {error && (
                <div className="wppo-notice wppo-notice--error" style={{ marginBottom: '15px' }}>
                    {error}
                </div>
            )}

            <div className="wppo-activity-list">
                {activities.length === 0 && !isFetching && !error && (
                    <p>{translations.noActivities || 'No recent activities to display.'}</p>
                )}
                {activities.length > 0 && (
                    <ul>
                        {activities.map((activity, index) => (
                            // Use a more unique key if activity.id can repeat across pages, e.g. `${activity.id}-${index}`
                            <li key={activity.id || index}>
                                <span className="wppo-activity-timestamp" style={{ marginRight: '10px', color: '#777', fontSize: '0.9em' }}>
                                    [{activity.created_at}]
                                </span>
                                <span className="wppo-activity-description" dangerouslySetInnerHTML={{ __html: activity.activity }} />
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {isFetching && (
                <div style={{ textAlign: 'center', padding: '20px' }}>
                    <FontAwesomeIcon icon={faSpinner} spin size="2x" />
                    <p>{translations.loadingActivities || 'Loading activities...'}</p>
                </div>
            )}

            {!isFetching && currentPage < totalPages && (
                <div style={{ textAlign: 'center', marginTop: '20px' }}>
                    <button
                        className="wppo-button"
                        onClick={handleLoadMore}
                        disabled={isFetching}
                    >
                        {translations.loadMore || 'Load More Activities'}
                    </button>
                </div>
            )}
        </div>
    );
};

export default ActivityLog;