import { HTMLAttributes, TdHTMLAttributes, ThHTMLAttributes } from 'react';
import { cn } from '../../lib/utils';

export function Table({ className, ...props }: HTMLAttributes<HTMLTableElement>) {
    return (
        <div className="w-full overflow-x-auto">
            <table className={cn('w-full text-left text-sm', className)} {...props} />
        </div>
    );
}

export function TableHead({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
    return <thead className={cn('border-b text-xs uppercase text-gray-500', className)} {...props} />;
}

export function TableBody({ className, ...props }: HTMLAttributes<HTMLTableSectionElement>) {
    return <tbody className={cn('divide-y', className)} {...props} />;
}

export function TableRow({ className, ...props }: HTMLAttributes<HTMLTableRowElement>) {
    return <tr className={cn('hover:bg-gray-50', className)} {...props} />;
}

export function TableHeadCell({ className, ...props }: ThHTMLAttributes<HTMLTableCellElement>) {
    return <th className={cn('px-3 py-2 font-medium', className)} {...props} />;
}

export function TableCell({ className, ...props }: TdHTMLAttributes<HTMLTableCellElement>) {
    return <td className={cn('px-3 py-2', className)} {...props} />;
}
