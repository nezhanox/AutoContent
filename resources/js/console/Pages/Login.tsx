import { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Clapperboard } from 'lucide-react';
import { Button } from '../components/ui/button';
import { Input } from '../components/ui/input';
import { Label } from '../components/ui/label';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        post('/console/login');
    }

    return (
        <div className="flex min-h-screen items-center justify-center bg-console-bg px-4">
            <div className="w-full max-w-sm">
                <div className="mb-6 flex flex-col items-center gap-3 text-center">
                    <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-console-accent to-indigo-400 text-white shadow-sm">
                        <Clapperboard className="h-5 w-5" strokeWidth={2.25} />
                    </span>
                    <div>
                        <h1 className="text-lg font-semibold text-console-text">AutoContent Console</h1>
                        <p className="text-sm text-console-text-muted">Sign in to manage your channels</p>
                    </div>
                </div>

                <form
                    onSubmit={submit}
                    className="space-y-4 rounded-2xl border border-console-border bg-console-surface p-6 shadow-[0_1px_2px_rgba(28,31,55,0.04)]"
                >
                    <div className="space-y-1">
                        <Label htmlFor="email">Email</Label>
                        <Input
                            id="email"
                            type="email"
                            autoFocus
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                        />
                        {errors.email && <p className="text-sm text-console-danger">{errors.email}</p>}
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="password">Password</Label>
                        <Input
                            id="password"
                            type="password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                        />
                        {errors.password && <p className="text-sm text-console-danger">{errors.password}</p>}
                    </div>

                    <Button type="submit" disabled={processing} className="w-full">
                        Sign in
                    </Button>
                </form>
            </div>
        </div>
    );
}
