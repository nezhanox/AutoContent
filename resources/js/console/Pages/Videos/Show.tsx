import React from 'react';

interface VideoShowProps {
    video: {
        id: number;
        title: string;
        description: string;
        status: string;
        stageLabel: string;
        stageColor: 'danger' | 'success' | 'warning' | 'default';
        canRetry: boolean;
        channel: string | null;
        channelId: number | null;
        idea: string | null;
        duration: number | null;
        width: number | null;
        height: number | null;
        createdAt: string | null;
        videoUrl: string | null;
        voiceoverUrl: string | null;
        musicAssetLabel: string | null;
        scriptText: string | null;
        subtitlesText: string | null;
        scenes: Array<{
            order: number;
            type: string;
            duration: number;
            text: string;
            visualQuery: string | null;
        }>;
        qualityPassed: boolean | null;
        qualityReport: Record<string, unknown> | null;
        errorMessage: string | null;
        failedStage: string | null;
    };
}

export default function Show({ video }: VideoShowProps) {
    return (
        <div>
            <h1>{video.title}</h1>
            <p>Video Show Page (Task 2 implementation pending)</p>
        </div>
    );
}
