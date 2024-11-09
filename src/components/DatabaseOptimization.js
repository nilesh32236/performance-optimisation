import React, { useState, useCallback } from 'react';
import debounce from '../lib/Debonce';
import CheckboxOption from './CheckboxOption';
import { handleChange, handleSubmit } from '../lib/formUtils';

const DatabaseOptimization = ({ options }) => {
	const [settings, setSettings] = useState({
		optimizeTables: options?.optimizeTables || false,
		repairTables: options?.repairTables || false,
		deleteRevisions: options?.deleteRevisions || false,
	});

	const [isLoading, setIsLoading] = useState(false);

	const debouncedHandleSubmit = useCallback(
		debounce( async() => {
			setIsLoading(true); // Start the loading state

			try {
				await handleSubmit(settings, 'database_optimization');
			} catch (error) {
				console.error('Form submission error:', error);
			} finally {
				setIsLoading(false);
			}
	
		}, 500 ),
		[ settings ]
	)

	const onSubmit = async (e) => {
		e.preventDefault();
		debouncedHandleSubmit();
	}

	return (
		<form onSubmit={onSubmit} className="database-optimization-form">
			<h2>Database Optimization Settings</h2>

			<CheckboxOption
				label="Optimize Database Tables"
				description="Optimize your database tables to improve site performance and reduce database size."
				name="optimizeTables"
				checked={settings.optimizeTables}
				onChange={handleChange(setSettings)}
			/>

			<CheckboxOption
				label="Repair Database Tables"
				description="Repair corrupted or damaged database tables to maintain the integrity of your data."
				name="repairTables"
				checked={settings.repairTables}
				onChange={handleChange(setSettings)}
			/>

			<CheckboxOption
				label="Delete Revisions"
				description="Remove old post revisions from the database to free up space and improve performance."
				name="deleteRevisions"
				checked={settings.deleteRevisions}
				onChange={handleChange(setSettings)}
			/>

			<button type="submit" className="submit-button" disabled={isLoading}>
				{isLoading ? 'Saving...' : 'Save Settings'}
			</button>
		</form>
	);
};

export default DatabaseOptimization;
