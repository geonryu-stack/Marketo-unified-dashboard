import type { Metadata } from 'next';
import { Geist, Geist_Mono } from 'next/font/google';
import './globals.css';
import { Sidebar } from '@/components/layout/sidebar';

const geistSans = Geist({ variable: '--font-geist-sans', subsets: ['latin'] });
const geistMono = Geist_Mono({ variable: '--font-geist-mono', subsets: ['latin'] });

export const metadata: Metadata = {
  title: 'Marketo 발송 자동화',
  description: '사내 DB 세그먼트 추출 → Marketo 예약 발송 자동화',
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="ko" className={`${geistSans.variable} ${geistMono.variable} h-full`}>
      <body className="min-h-full flex">
        <Sidebar />
        <main className="flex-1 flex flex-col min-h-screen overflow-auto">
          {children}
        </main>
      </body>
    </html>
  );
}
