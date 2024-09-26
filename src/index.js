import App from "./App";
import { createRoot } from 'react-dom/client';

import './css/style.scss';

const rootElement = document.getElementById('performance-optimisation');
if (rootElement) {
	const root = createRoot(rootElement);

	root.render(<App />);
}
