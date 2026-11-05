/* global mwEditorGrowth */
// Change "edit" to "edit this page"
( function () {
	document.addEventListener( 'DOMContentLoaded', () => {
		for ( const selector of [
			'#ca-ve-edit a span', '#ca-edit a span'
		] ) {
			const element = document.querySelector( selector );
			if ( element ) {
				element.textContent =
          mwEditorGrowth.experiments[ 'edit-button-prominence-v1' ].messages[ 'editorgrowth-editthispage' ];
			}
		}
	} );
}() );
