import { useEffect, useState } from 'react'
import { clearLoginSkeleton, isLoginSkeletonPending } from '../utils/loginSkeletonState'

export default function useLoginSkeleton() {
    // Read without consuming during render, including Strict Mode's checks.
    const [showLoginSkeleton] = useState(isLoginSkeletonPending)

    useEffect(() => {
        if (showLoginSkeleton) clearLoginSkeleton()
    }, [showLoginSkeleton])

    return showLoginSkeleton
}
