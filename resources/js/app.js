import './bootstrap';
import './components/building-plan-chat-widget.css';
import { mountBuildingPlanChatWidgets } from './components/BuildingPlanChatWidget';

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mountBuildingPlanChatWidgets);
} else {
    mountBuildingPlanChatWidgets();
}
