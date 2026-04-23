'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import {
  LayoutDashboard,
  Users,
  Image as ImageIcon,
  Send,
  PlusCircle,
  Zap,
  Calendar,
} from 'lucide-react';
import { cn } from '@/lib/utils';

const NAV_ITEMS = [
  { href: '/', label: '대시보드', icon: LayoutDashboard },
  { href: '/send', label: '새 발송', icon: Calendar },
  { href: '/segments', label: '세그먼트', icon: Users },
  { href: '/assets', label: '에셋 라이브러리', icon: ImageIcon },
  { href: '/campaigns', label: '캠페인', icon: Send },
];

export function Sidebar() {
  const pathname = usePathname();

  return (
    <aside className="w-60 min-h-screen flex flex-col bg-slate-800 text-slate-200">
      {/* Logo */}
      <div className="flex items-center gap-2 px-5 py-5 border-b border-slate-700">
        <Zap className="h-5 w-5 text-indigo-400" />
        <span className="font-bold text-white tracking-tight">Marketo Auto</span>
      </div>

      {/* Nav */}
      <nav className="flex-1 px-3 py-4 space-y-1">
        {NAV_ITEMS.map(({ href, label, icon: Icon }) => {
          const active =
            href === '/' ? pathname === '/' : pathname.startsWith(href);
          return (
            <Link
              key={href}
              href={href}
              className={cn(
                'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                active
                  ? 'bg-indigo-600 text-white'
                  : 'text-slate-300 hover:bg-slate-700 hover:text-white'
              )}
            >
              <Icon className="h-4 w-4" />
              {label}
            </Link>
          );
        })}
      </nav>

      {/* Quick action */}
      <div className="px-3 pb-5">
        <Link
          href="/campaigns/new"
          className="flex items-center gap-2 w-full justify-center rounded-lg bg-indigo-600 hover:bg-indigo-500 px-4 py-2 text-sm font-semibold text-white transition-colors"
        >
          <PlusCircle className="h-4 w-4" />
          새 캠페인
        </Link>
      </div>
    </aside>
  );
}
