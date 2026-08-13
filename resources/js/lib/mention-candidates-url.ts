/**
 * Adds the picker's search term to a candidates endpoint the call site handed over whole — any
 * query it carries rides along untouched.
 *
 * The picker used to assemble the endpoint's scope itself and once spelled a parameter the server
 * did not read, which silently served the SNS-wide roster to a scoped composer: names the submit
 * would then drop, which is exactly the invariant a candidates endpoint exists to hold. Handing the
 * whole URL over removes the chance to guess a parameter name at all.
 */
export function candidatesUrlFor(endpoint: string, query: string): string {
    return `${endpoint}${endpoint.includes('?') ? '&' : '?'}q=${encodeURIComponent(query)}`;
}
