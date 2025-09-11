/**
 * Interactive Optimization Controls Component
 *
 * Provides real-time optimization controls with immediate feedback
 * and progress tracking.
 *
 * @package PerformanceOptimisation
 * @since 2.0.0
 */

import React, { useState, useEffect } from 'react';
import { Card, Button, ProgressBar, Toggle, Select } from '@components/index';

interface OptimizationTask {
	id: string;
	name: string;
	description: string;
	status: 'idle' | 'running' | 'completed' | 'error';
	progress: number;
	result?: {
		success: boolean;
		message: string;
		stats?: {
			processed: number;
			optimized: number;
			savings: string;
		};
	};
}

interface OptimizationSettings {
	cache_enabled: boolean;
	minification_enabled: boolean;
	image_optimization_enabled: boolean;
	lazy_loading_enabled: boolean;
	compression_level: number;
	image_quality: number;
}

export const InteractiveOptimizationControls: React.FC = () => {
	const [tasks, setTasks] = useState<OptimizationTask[]>([
		{
			id: 'clear_cache',
			name: 'Clear All Cache',
			description: 'Clear page cache, object cache, and minified files',
			status: 'idle',
			progress: 0,
		},
		{
			id: 'optimize_images',
			name: 'Optimize Images',
			description: 'Convert and compress images for better performance',
			status: 'idle',
			progress: 0,
		},
		{
			id: 'minify_assets',
			name: 'Minify CSS/JS',
			description: 'Minify and combine CSS and JavaScript files',
			status: 'idle',
			progress: 0,
		},
		{
			id: 'preload_cache',
			name: 'Preload Cache',
			description: 'Generate cache for important pages',
			status: 'idle',
			progress: 0,
		},
		{
			id: 'analyze_performance',
			name: 'Analyze Performance',
			description: 'Run comprehensive performance analysis',
			status: 'idle',
			progress: 0,
		},
	]);

	const [settings, setSettings] = useState<OptimizationSettings>({
		cache_enabled: true,
		minification_enabled: true,
		image_optimization_enabled: true,
		lazy_loading_enabled: true,
		compression_level: 6,
		image_quality: 85,
	});

	const [isRunningBatch, setIsRunningBatch] = useState(false);
	const [batchProgress, setBatchProgress] = useState(0);

	/**
	 * Execute a single optimization task
	 */
	const executeTask = async (taskId: string) => {
		setTasks(prev => prev.map(task => 
			task.id === taskId 
				? { ...task, status: 'running', progress: 0 }
				: task
		));

		try {
			const response = await fetch(`${window.wppoAdmin?.apiUrl}/optimization/${taskId}`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': window.wppoAdmin?.nonce || '',
				},
				body: JSON.stringify({ settings }),
			});

			if (response.ok) {
				const result = await response.json();
				
				// Simulate progress updates
				for (let progress = 10; progress <= 100; progress += 10) {
					await new Promise(resolve => setTimeout(resolve, 200));
					setTasks(prev => prev.map(task => 
						task.id === taskId 
							? { ...task, progress }
							: task
					));
				}

				setTasks(prev => prev.map(task => 
					task.id === taskId 
						? { 
							...task, 
							status: result.success ? 'completed' : 'error',
							progress: 100,
							result: result
						}
						: task
				));
			} else {
				throw new Error('Task execution failed');
			}
		} catch (error) {
			setTasks(prev => prev.map(task => 
				task.id === taskId 
					? { 
						...task, 
						status: 'error',
						progress: 0,
						result: {
							success: false,
							message: error instanceof Error ? error.message : 'Unknown error occurred'
						}
					}
					: task
			));
		}
	};

	/**
	 * Execute all optimization tasks in sequence
	 */
	const executeAllTasks = async () => {
		setIsRunningBatch(true);
		setBatchProgress(0);

		const enabledTasks = tasks.filter(task => {
			// Filter based on current settings
			if (task.id === 'clear_cache' && !settings.cache_enabled) return false;
			if (task.id === 'minify_assets' && !settings.minification_enabled) return false;
			if (task.id === 'optimize_images' && !settings.image_optimization_enabled) return false;
			return true;
		});

		for (let i = 0; i < enabledTasks.length; i++) {
			await executeTask(enabledTasks[i].id);
			setBatchProgress(((i + 1) / enabledTasks.length) * 100);
		}

		setIsRunningBatch(false);
	};

	/**
	 * Reset all tasks
	 */
	const resetTasks = () => {
		setTasks(prev => prev.map(task => ({
			...task,
			status: 'idle',
			progress: 0,
			result: undefined,
		})));
		setBatchProgress(0);
	};

	/**
	 * Update settings
	 */
	const updateSetting = (key: keyof OptimizationSettings, value: any) => {
		setSettings(prev => ({
			...prev,
			[key]: value,
		}));
	};

	/**
	 * Get task status icon
	 */
	const getTaskStatusIcon = (status: OptimizationTask['status']) => {
		switch (status) {
			case 'running':
				return '⏳';
			case 'completed':
				return '✅';
			case 'error':
				return '❌';
			default:
				return '⚪';
		}
	};

	/**
	 * Get task status color
	 */
	const getTaskStatusColor = (status: OptimizationTask['status']) => {
		switch (status) {
			case 'running':
				return '#f39c12';
			case 'completed':
				return '#27ae60';
			case 'error':
				return '#e74c3c';
			default:
				return '#95a5a6';
		}
	};

	return (
		<Card title="Interactive Optimization Controls" className="wppo-optimization-controls">
			{/* Settings Panel */}
			<div className="wppo-optimization-controls__settings">
				<h3>Optimization Settings</h3>
				<div className="wppo-settings-grid">
					<div className="wppo-setting-item">
						<Toggle
							label="Enable Caching"
							checked={settings.cache_enabled}
							onChange={(checked) => updateSetting('cache_enabled', checked)}
						/>
					</div>
					<div className="wppo-setting-item">
						<Toggle
							label="Enable Minification"
							checked={settings.minification_enabled}
							onChange={(checked) => updateSetting('minification_enabled', checked)}
						/>
					</div>
					<div className="wppo-setting-item">
						<Toggle
							label="Enable Image Optimization"
							checked={settings.image_optimization_enabled}
							onChange={(checked) => updateSetting('image_optimization_enabled', checked)}
						/>
					</div>
					<div className="wppo-setting-item">
						<Toggle
							label="Enable Lazy Loading"
							checked={settings.lazy_loading_enabled}
							onChange={(checked) => updateSetting('lazy_loading_enabled', checked)}
						/>
					</div>
					<div className="wppo-setting-item">
						<label>Compression Level</label>
						<Select
							value={settings.compression_level}
							onChange={(value) => updateSetting('compression_level', parseInt(value))}
							options={[
								{ value: 1, label: 'Low (1)' },
								{ value: 3, label: 'Medium (3)' },
								{ value: 6, label: 'High (6)' },
								{ value: 9, label: 'Maximum (9)' },
							]}
						/>
					</div>
					<div className="wppo-setting-item">
						<label>Image Quality</label>
						<Select
							value={settings.image_quality}
							onChange={(value) => updateSetting('image_quality', parseInt(value))}
							options={[
								{ value: 60, label: 'Low (60%)' },
								{ value: 75, label: 'Medium (75%)' },
								{ value: 85, label: 'High (85%)' },
								{ value: 95, label: 'Maximum (95%)' },
							]}
						/>
					</div>
				</div>
			</div>

			{/* Batch Controls */}
			<div className="wppo-optimization-controls__batch">
				<div className="wppo-batch-header">
					<h3>Batch Operations</h3>
					<div className="wppo-batch-controls">
						<Button
							variant="primary"
							onClick={executeAllTasks}
							disabled={isRunningBatch}
							loading={isRunningBatch}
						>
							{isRunningBatch ? 'Running...' : 'Run All Optimizations'}
						</Button>
						<Button
							variant="secondary"
							onClick={resetTasks}
							disabled={isRunningBatch}
						>
							Reset All
						</Button>
					</div>
				</div>
				
				{isRunningBatch && (
					<div className="wppo-batch-progress">
						<ProgressBar progress={batchProgress} />
						<span className="wppo-progress-text">
							Overall Progress: {Math.round(batchProgress)}%
						</span>
					</div>
				)}
			</div>

			{/* Individual Tasks */}
			<div className="wppo-optimization-controls__tasks">
				<h3>Individual Tasks</h3>
				<div className="wppo-tasks-list">
					{tasks.map((task) => (
						<div key={task.id} className="wppo-task-item">
							<div className="wppo-task-info">
								<div className="wppo-task-header">
									<span 
										className="wppo-task-status"
										style={{ color: getTaskStatusColor(task.status) }}
									>
										{getTaskStatusIcon(task.status)}
									</span>
									<h4 className="wppo-task-name">{task.name}</h4>
									<Button
										variant="tertiary"
										size="small"
										onClick={() => executeTask(task.id)}
										disabled={task.status === 'running' || isRunningBatch}
									>
										{task.status === 'running' ? 'Running...' : 'Run'}
									</Button>
								</div>
								<p className="wppo-task-description">{task.description}</p>
								
								{task.status === 'running' && (
									<div className="wppo-task-progress">
										<ProgressBar progress={task.progress} size="small" />
										<span className="wppo-progress-text">{task.progress}%</span>
									</div>
								)}
								
								{task.result && (
									<div className={`wppo-task-result ${task.result.success ? 'success' : 'error'}`}>
										<p>{task.result.message}</p>
										{task.result.stats && (
											<div className="wppo-task-stats">
												<span>Processed: {task.result.stats.processed}</span>
												<span>Optimized: {task.result.stats.optimized}</span>
												<span>Savings: {task.result.stats.savings}</span>
											</div>
										)}
									</div>
								)}
							</div>
						</div>
					))}
				</div>
			</div>
		</Card>
	);
};
