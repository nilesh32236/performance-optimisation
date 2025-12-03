/**
 * Storage Mode Toggle Component
 *
 * Allows users to choose between Safe Mode and Space Saver Mode
 *
 * @package PerformanceOptimisation
 * @since 2.0.0
 */

import React, { useState } from 'react';
import { Card } from '../index';

interface StorageModeProps {
    value: 'safe' | 'space_saver';
    onChange: (mode: 'safe' | 'space_saver') => void;
}

export const StorageModeToggle: React.FC<StorageModeProps> = ({ value, onChange }) => {
    const [showWarning, setShowWarning] = useState(false);
    const [pendingMode, setPendingMode] = useState<'safe' | 'space_saver' | null>(null);

    const handleModeChange = (mode: 'safe' | 'space_saver') => {
        if (mode === 'space_saver' && value === 'safe') {
            // Show warning before switching to Space Saver
            setPendingMode(mode);
            setShowWarning(true);
        } else {
            onChange(mode);
        }
    };

    const confirmSpaceSaver = () => {
        if (pendingMode) {
            onChange(pendingMode);
            setShowWarning(false);
            setPendingMode(null);
        }
    };

    const cancelSpaceSaver = () => {
        setShowWarning(false);
        setPendingMode(null);
    };

    return (
        <Card title="Storage Mode">
            <div className="wppo-storage-mode">
                <p className="wppo-storage-mode__description">
                    Choose how to handle original images after converting to WebP/AVIF:
                </p>

                <div className="wppo-storage-mode__options">
                    <label className={`wppo-storage-mode__option ${value === 'safe' ? 'wppo-storage-mode__option--active' : ''}`}>
                        <input
                            type="radio"
                            name="storage_mode"
                            value="safe"
                            checked={value === 'safe'}
                            onChange={() => handleModeChange('safe')}
                        />
                        <div className="wppo-storage-mode__option-content">
                            <strong>Safe Mode (Recommended)</strong>
                            <p>
                                Keep original images. Uses more disk space but allows
                                reverting to originals if needed.
                            </p>
                        </div>
                    </label>

                    <label className={`wppo-storage-mode__option ${value === 'space_saver' ? 'wppo-storage-mode__option--active' : ''}`}>
                        <input
                            type="radio"
                            name="storage_mode"
                            value="space_saver"
                            checked={value === 'space_saver'}
                            onChange={() => handleModeChange('space_saver')}
                        />
                        <div className="wppo-storage-mode__option-content">
                            <strong>Space Saver Mode</strong>
                            <p>
                                Delete originals after successful conversion. Saves
                                ~70-80% disk space but irreversible.
                            </p>
                            {value === 'space_saver' && (
                                <div className="wppo-storage-mode__warning">
                                    ⚠️ <strong>Warning:</strong> Original files will be permanently deleted
                                </div>
                            )}
                        </div>
                    </label>
                </div>

                <div className="wppo-storage-mode__info">
                    <p>
                        <strong>Estimated space savings:</strong>{' '}
                        {value === 'space_saver' ? '~70-80% per image' : 'None (keeping originals)'}
                    </p>
                </div>

                {/* Warning Dialog */}
                {showWarning && (
                    <div className="wppo-modal-overlay">
                        <div className="wppo-modal">
                            <div className="wppo-modal__header">
                                <h3>⚠️ Enable Space Saver Mode?</h3>
                            </div>
                            <div className="wppo-modal__content">
                                <p><strong>This action cannot be undone.</strong></p>
                                <p>When enabled, original images will be permanently deleted after successful conversion to WebP/AVIF.</p>
                                <ul>
                                    <li>You cannot revert to original formats</li>
                                    <li>Make sure you have backups</li>
                                    <li>Only use if disk space is critical</li>
                                </ul>
                                <p>Are you sure you want to continue?</p>
                            </div>
                            <div className="wppo-modal__actions">
                                <button
                                    className="wppo-button wppo-button--secondary"
                                    onClick={cancelSpaceSaver}
                                >
                                    Cancel
                                </button>
                                <button
                                    className="wppo-button wppo-button--danger"
                                    onClick={confirmSpaceSaver}
                                >
                                    Enable Space Saver Mode
                                </button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </Card>
    );
};
