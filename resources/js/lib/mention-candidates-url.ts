/**
 * The call site hands the endpoint over whole, so nothing here guesses a parameter name: a scope
 * parameter the server does not read silently serves the SNS-wide roster to a scoped composer. Any
 * query the URL already carries rides along untouched.
 */
export function candidatesUrlFor(endpoint: string, query: string): string {
    return `${endpoint}${endpoint.includes('?') ? '&' : '?'}q=${encodeURIComponent(query)}`;
}
