import { useEffect, useRef, useState } from 'react'

export default function useAutoDismissNotice(duration = 4000) {
    const [notice, setNotice] = useState('')
    const timeoutRef = useRef(null)

    useEffect(() => () => window.clearTimeout(timeoutRef.current), [])

    function clearNotice() {
        window.clearTimeout(timeoutRef.current)
        timeoutRef.current = null
        setNotice('')
    }

    function showNotice(message) {
        window.clearTimeout(timeoutRef.current)
        setNotice(message)
        timeoutRef.current = window.setTimeout(() => {
            setNotice('')
            timeoutRef.current = null
        }, duration)
    }

    return [notice, showNotice, clearNotice]
}
