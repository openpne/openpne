import { Head, Link, usePage } from '@inertiajs/react';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { TimelinePostEntry } from './types';

interface ShowProps extends PageProps {
    post: TimelinePostEntry;
}

export default function TimelineShow() {
    const t = useT();
    const { post } = usePage<ShowProps>().props;
    const title = t(":name's %activity%", { name: post.author.name });

    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">{title}</h1>

                <article className="space-y-2 border-b pb-4">
                    <div className="flex items-center justify-between text-sm">
                        <Link href={`/m/member/${post.author.id}/timeline`} className="font-medium hover:underline">
                            {post.author.name}
                        </Link>
                        <span className="text-muted-foreground">{new Date(post.createdAt).toLocaleString()}</span>
                    </div>
                    <p className="whitespace-pre-wrap">{post.body}</p>
                    {post.images.length > 0 && (
                        <div className="flex flex-wrap gap-2">
                            {post.images.map((image) => (
                                <img key={image.id} src={image.thumbnailUrl} alt="" className="rounded" />
                            ))}
                        </div>
                    )}
                </article>

                <Link href={`/m/member/${post.author.id}/timeline`} className="hover:underline">
                    {t(":name's %activity%", { name: post.author.name })}
                </Link>
            </main>
        </>
    );
}
