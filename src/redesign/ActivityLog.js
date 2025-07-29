import React from 'react';

import React, { useState, useEffect } from 'react';

function ActivityLog({ adminData, isLoading, setIsLoading }) {
  const { translations = {}, apiUrl, nonce } = adminData || {};
  const [logs, setLogs] = useState([]);
  const [page, setPage] = useState(1);
  const [canLoadMore, setCanLoadMore] = useState(true);

  const fetchLogs = async (pageNum) => {
    setIsLoading(true);
    try {
      const response = await fetch(`${apiUrl}logs?page=${pageNum}`, {
        headers: {
          'X-WP-Nonce': nonce,
        },
      });
      const result = await response.json();
      if (result.success) {
        setLogs(prevLogs => (pageNum === 1 ? result.data.logs : [...prevLogs, ...result.data.logs]));
        setCanLoadMore(result.data.has_more);
      }
    } catch (error) {
      console.error('Error fetching logs:', error);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchLogs(1);
  }, []);

  const handleLoadMore = () => {
    const newPage = page + 1;
    setPage(newPage);
    fetchLogs(newPage);
  };

  return (
    <div>
      <h1>{translations.activityLog || 'Activity Log'}</h1>
      <div className="wppo-card">
        <table>
          <thead>
            <tr>
              <th>{translations.date || 'Date'}</th>
              <th>{translations.message || 'Message'}</th>
            </tr>
          </thead>
          <tbody>
            {logs.map((log, index) => (
              <tr key={index}>
                <td>{log.timestamp}</td>
                <td>{log.message}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {canLoadMore && (
          <button
            className="wppo-button"
            onClick={handleLoadMore}
            disabled={isLoading}
          >
            {isLoading ? (translations.loadingActivities || 'Loading Activities...') : (translations.loadMore || 'Load More')}
          </button>
        )}
      </div>
    </div>
  );
}

export default ActivityLog;
