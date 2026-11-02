// Change "edit source" into "edit"
( function () {
	if (
		!document.documentElement.classList.contains( 'ext-editorgrowth-single-edit-tab-v1-source-only' )
	) {
		return;
	}
	document.addEventListener( 'DOMContentLoaded', () => {
		for ( const [ from, to ] of [
			[ '#ca-ve-edit a span', '#ca-edit a span' ],
			[ '#ca-more-ve-edit a span', '#ca-more-edit a span' ]
		] ) {
			const source = document.querySelector( from );
			const target = document.querySelector( to );
			if ( source && target ) {
				target.textContent = source.textContent;
			}
		}
	} );
}() );
