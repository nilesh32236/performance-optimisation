import React, { useState, useEffect } from 'react';
import { Button, Card, Progress, Notice } from './UI';
import { runComponentTests, TestResult } from '../utils/testing';

interface TestSuite {
	name: string;
	component: React.ComponentType;
	tests: {
		accessibility: TestResult[];
		security: TestResult[];
		api?: TestResult;
	} | null;
	status: 'pending' | 'running' | 'completed' | 'failed';
}

export const TestRunner: React.FC = () => {
	const [testSuites, setTestSuites] = useState<TestSuite[]>([
		{ name: 'CachingTab', component: () => null, tests: null, status: 'pending' },
		{ name: 'OptimizationTab', component: () => null, tests: null, status: 'pending' },
		{ name: 'ImagesTab', component: () => null, tests: null, status: 'pending' },
		{ name: 'AdvancedTab', component: () => null, tests: null, status: 'pending' },
		{ name: 'DashboardAnalytics', component: () => null, tests: null, status: 'pending' }
	]);
	
	const [running, setRunning] = useState(false);
	const [progress, setProgress] = useState(0);
	const [results, setResults] = useState<{
		total: number;
		passed: number;
		failed: number;
		warnings: number;
	}>({ total: 0, passed: 0, failed: 0, warnings: 0 });

	const runTests = async () => {
		setRunning(true);
		setProgress(0);
		
		const updatedSuites = [...testSuites];
		let completedTests = 0;
		
		for (let i = 0; i < updatedSuites.length; i++) {
			const suite = updatedSuites[i];
			suite.status = 'running';
			setTestSuites([...updatedSuites]);
			
			try {
				// Find the component in DOM (simplified approach)
				const element = document.querySelector(`[data-testid="${suite.name}"]`) as HTMLElement;
				
				if (element) {
					suite.tests = await runComponentTests(element, suite.name);
					suite.status = 'completed';
				} else {
					suite.tests = {
						accessibility: [{ passed: false, message: 'Component not found in DOM' }],
						security: []
					};
					suite.status = 'failed';
				}
			} catch (error) {
				suite.tests = {
					accessibility: [{ passed: false, message: `Test failed: ${error}` }],
					security: []
				};
				suite.status = 'failed';
			}
			
			completedTests++;
			setProgress((completedTests / updatedSuites.length) * 100);
			setTestSuites([...updatedSuites]);
		}
		
		// Calculate results
		const allTests = updatedSuites.flatMap(suite => [
			...(suite.tests?.accessibility || []),
			...(suite.tests?.security || []),
			...(suite.tests?.api ? [suite.tests.api] : [])
		]);
		
		setResults({
			total: allTests.length,
			passed: allTests.filter(t => t.passed).length,
			failed: allTests.filter(t => !t.passed).length,
			warnings: allTests.filter(t => t.message.includes('warning')).length
		});
		
		setRunning(false);
	};

	const getStatusIcon = (status: TestSuite['status']) => {
		switch (status) {
			case 'completed': return '✅';
			case 'failed': return '❌';
			case 'running': return '⏳';
			default: return '⚪';
		}
	};

	const getResultColor = (passed: boolean) => passed ? 'success' : 'error';

	return (
		<div className="wppo-test-runner" data-testid="TestRunner">
			<Card title="Component Testing Suite">
				<div className="wppo-test-header">
					<p>Run comprehensive tests on React components to ensure security, accessibility, and functionality.</p>
					<Button 
						variant="primary" 
						onClick={runTests} 
						disabled={running}
						aria-label={running ? 'Tests running...' : 'Run all tests'}
					>
						{running ? 'Running Tests...' : 'Run All Tests'}
					</Button>
				</div>

				{running && (
					<div className="wppo-test-progress" role="status" aria-label={`Testing progress: ${Math.round(progress)}%`}>
						<Progress value={progress} label="Test Progress" showPercentage />
					</div>
				)}

				{results.total > 0 && (
					<div className="wppo-test-summary">
						<h3>Test Results Summary</h3>
						<div className="wppo-results-grid">
							<div className="wppo-result-item wppo-result-total">
								<span className="wppo-result-value">{results.total}</span>
								<span className="wppo-result-label">Total Tests</span>
							</div>
							<div className="wppo-result-item wppo-result-passed">
								<span className="wppo-result-value">{results.passed}</span>
								<span className="wppo-result-label">Passed</span>
							</div>
							<div className="wppo-result-item wppo-result-failed">
								<span className="wppo-result-value">{results.failed}</span>
								<span className="wppo-result-label">Failed</span>
							</div>
							<div className="wppo-result-item wppo-result-warnings">
								<span className="wppo-result-value">{results.warnings}</span>
								<span className="wppo-result-label">Warnings</span>
							</div>
						</div>
					</div>
				)}

				<div className="wppo-test-suites">
					{testSuites.map((suite, index) => (
						<div key={suite.name} className={`wppo-test-suite wppo-test-suite--${suite.status}`}>
							<div className="wppo-test-suite-header">
								<span className="wppo-test-status">{getStatusIcon(suite.status)}</span>
								<h4>{suite.name}</h4>
								<span className="wppo-test-suite-status">{suite.status}</span>
							</div>

							{suite.tests && (
								<div className="wppo-test-details">
									<div className="wppo-test-category">
										<h5>Accessibility Tests</h5>
										{suite.tests.accessibility.map((test, testIndex) => (
											<div key={testIndex} className={`wppo-test-result wppo-test-result--${getResultColor(test.passed)}`}>
												<span className="wppo-test-icon">{test.passed ? '✅' : '❌'}</span>
												<span className="wppo-test-message">{test.message}</span>
											</div>
										))}
									</div>

									<div className="wppo-test-category">
										<h5>Security Tests</h5>
										{suite.tests.security.map((test, testIndex) => (
											<div key={testIndex} className={`wppo-test-result wppo-test-result--${getResultColor(test.passed)}`}>
												<span className="wppo-test-icon">{test.passed ? '✅' : '❌'}</span>
												<span className="wppo-test-message">{test.message}</span>
											</div>
										))}
									</div>

									{suite.tests.api && (
										<div className="wppo-test-category">
											<h5>API Tests</h5>
											<div className={`wppo-test-result wppo-test-result--${getResultColor(suite.tests.api.passed)}`}>
												<span className="wppo-test-icon">{suite.tests.api.passed ? '✅' : '❌'}</span>
												<span className="wppo-test-message">{suite.tests.api.message}</span>
											</div>
										</div>
									)}
								</div>
							)}
						</div>
					))}
				</div>
			</Card>
		</div>
	);
};
