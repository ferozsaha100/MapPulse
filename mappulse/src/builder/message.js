// A message a person can act on, whatever shape the failure has.
export const messageOf = ( error, fallback ) =>
	( error && typeof error.message === 'string' && error.message ) || fallback;
