// assets/js/sidebar/index.js
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';
import SidebarPanel from './components/SidebarPanel';

registerPlugin( 'navne-entity-sidebar', {
	render: () => (
		<>
			<PluginSidebarMoreMenuItem target="navne-sidebar">
				{ __( 'Entities', 'navne' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar name="navne-sidebar" title={ __( 'Entities', 'navne' ) }>
				<SidebarPanel />
			</PluginSidebar>
		</>
	),
} );
