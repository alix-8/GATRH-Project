/**
 * Returns the appropriate header title based on record status on attendance page drawer/overlay
 * @param {string|undefined} status 
 * @returns {string}
 */
export const headerTitle = (status) => {
    switch (status) {
        case 'pending':
            return 'Pending Submission';
        case 'approved':
            return 'Submission Approved';
        default:
            return 'Review Submission';
    }
};
