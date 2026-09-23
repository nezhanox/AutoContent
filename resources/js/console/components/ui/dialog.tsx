import { HTMLAttributes, PropsWithChildren, useEffect } from 'react';
import { createPortal } from 'react-dom';
import { cn } from '../../lib/utils';

interface DialogProps extends PropsWithChildren {
    open: boolean;
    onClose: () => void;
}

export function Dialog({ open, onClose, children }: DialogProps) {
    useEffect(() => {
        if (!open) return;

        function handleKeyDown(event: KeyboardEvent) {
            if (event.key === 'Escape') onClose();
        }

        document.addEventListener('keydown', handleKeyDown);
        return () => document.removeEventListener('keydown', handleKeyDown);
    }, [open, onClose]);

    if (!open) return null;

    return createPortal(
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-console-text/40 p-4">
            <div
                className="w-full max-w-md rounded-2xl border border-console-border bg-white p-6 shadow-xl"
                role="dialog"
                aria-modal="true"
            >
                {children}
            </div>
        </div>,
        document.body,
    );
}

export function DialogHeader({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('mb-4', className)} {...props} />;
}

export function DialogTitle({ className, ...props }: HTMLAttributes<HTMLHeadingElement>) {
    return <h2 className={cn('text-lg font-semibold text-console-text', className)} {...props} />;
}

export function DialogFooter({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('mt-6 flex justify-end gap-2', className)} {...props} />;
}
