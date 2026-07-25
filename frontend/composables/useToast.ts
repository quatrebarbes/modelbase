type ToastType = 'success' | 'error'
type Toast = { id: number; message: string; type: ToastType }

let nextId = 0

export function useToast() {
  const toasts = useState<Toast[]>('toasts', () => [])

  function dismiss(id: number) {
    toasts.value = toasts.value.filter((toast) => toast.id !== id)
  }

  function show(message: string, type: ToastType = 'success', duration = 2500) {
    const id = nextId++
    toasts.value.push({ id, message, type })
    setTimeout(() => dismiss(id), duration)
  }

  return { toasts, show, dismiss }
}
