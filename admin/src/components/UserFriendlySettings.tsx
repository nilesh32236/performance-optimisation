import React from 'react';
import { Switch, Tooltip } from '@wordpress/components';

interface SettingProps {
  id: string;
  label: string;
  description: string;
  impact: 'low' | 'medium' | 'high';
  risk: 'safe' | 'caution' | 'advanced';
  checked: boolean;
  onChange: (checked: boolean) => void;
}

const Setting: React.FC<SettingProps> = ({ id, label, description, impact, risk, checked, onChange }) => {
  const getImpactIcon = () => {
    switch(impact) {
      case 'high': return '🚀';
      case 'medium': return '⚡';
      default: return '📈';
    }
  };

  const getRiskColor = () => {
    switch(risk) {
      case 'safe': return 'green';
      case 'caution': return 'orange';
      default: return 'red';
    }
  };

  return (
    <div className={`wppo-setting wppo-setting--${risk}`}>
      <Switch checked={checked} onChange={onChange} />
      <div className="wppo-setting-content">
        <div className="wppo-setting-header">
          <h4>{label} {getImpactIcon()}</h4>
          <Tooltip text={`Risk Level: ${risk}`}>
            <span className={`wppo-risk-indicator wppo-risk--${risk}`} style={{color: getRiskColor()}}>
              ●
            </span>
          </Tooltip>
        </div>
        <p>{description}</p>
      </div>
    </div>
  );
};

export default Setting;
