import React from 'react';

interface ContentItem {
	label: string;
	description: string;
}

interface SecureContent {
	text: string;
	items?: ContentItem[];
}

interface SecureContentRendererProps {
	content: SecureContent | string;
}

export const SecureContentRenderer: React.FC<SecureContentRendererProps> = ({ content }) => {
	if (typeof content === 'string') {
		return <p>{content}</p>;
	}

	return (
		<div>
			<p>{content.text}</p>
			{content.items && (
				<ul>
					{content.items.map((item, index) => (
						<li key={index}>
							<strong>{item.label}:</strong> {item.description}
						</li>
					))}
				</ul>
			)}
		</div>
	);
};
