/**
 * Adds the picker's search term to a candidates endpoint.
 *
 * The endpoint arrives whole from the call site — `/timeline/mention-candidates`, the same with a
 * `?community=` scope, or a talk's own `/groups/{id}/talk/mention-candidates`. The picker used to
 * build that scope itself from a `groupId` prop and spelled the parameter `group` where the server
 * reads `community`, which silently served the SNS-wide roster to a community composer: names the
 * submit would then drop, which is exactly the invariant the endpoint exists to hold. Handing the
 * whole URL over removes the chance to name a parameter the server does not read.
 */
export function candidatesUrlFor(endpoint: string, query: string): string {
    return `${endpoint}${endpoint.includes('?') ? '&' : '?'}q=${encodeURIComponent(query)}`;
}
