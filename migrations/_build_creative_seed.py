#!/usr/bin/env python3
"""Generate idempotent SQL seed for Nadha's BugCreative tracker."""

from __future__ import annotations

import re
import uuid
from datetime import datetime
from pathlib import Path

RAW = r'''1			Poster	Insta	30 minute free webinar	Published	
2			Poster	insta	Ajmal p webinar	Published	
3			Poster	insta	Niba Webinar Poster 	Completed	
4			Poster	insta	No code no problem Webinar poster	Completed	
5			Poster Carousel	insta	Carousel Upgrade your skills with codo academy	Published	
6			Poster	insta	Vote codo 	Published	
7			Poster	insta	May 4 Admission started	Published	
8			Poster	insta	Debate	Published	
9			Poster	insta	Banner	Published	
10			Poster Carousel	insta	Vazha carousel	Published	
11			Poster	insta	VIshu poster	Published	
12			Poster	insta	VIshu poster	Published	
13			Poster	insta	VIshu poster	Published	
14			Poster	insta	Codo Academy logo	Published	
15			Reel	insta	Ajmal Sir 30 days web designing development	Published	
16			Reel	insta	Ajmal sir Admission started May 4th	Published	
17			Reel	insta	Ajmal sir Oru entrepreneur aano ningal agency undaakeettundu groupil share akeeknu	Published	
18			Reel	insta	NK SIR REEL AGENCY completed no publishing	Published	
19			Poster	insta	Reel cover Page	Published	
20	19-04-2026	Sunday	Carousel 	Insta	Dont share	Published	
21	19-04-2026	Sunday	Reel	Insta	Ajmal sir youtube nookiyitt	Published	
22	20-04-2026	Monday	Poster	Insta	Plus two kayinjoo	Published	
23	20-04-2026	Monday	Reel	Insta	Website DEvelop ajmal p 	Published	
24	21-04-2026	Tuesday	Poster	Insta	Stop scrolling May 4th	Published	
25	21-04-2026	Tuesday	Reel	insta	PLUS TWO kayinju MAy 4th New Batch admission Ajmal P sir	Published	
26	22-04-2026	Wednesday	Reel 	Insta	Professinal developer ajmal p sir	Published	
27	22-04-2026	Wednesday	Poster	Insta	30 dhivasam mathi	Published	
28	23-04-2026	Thursday	Reel	Insta	Oru carier gap Ajmal p sir	Published	
29	23-04-2026	Thursday	Poster	insta	Your side hustle starts here	Published	
30	24-04-2026	Friday	Reel	insta	Swandham varumaanam Ajmal p sir 	Published	
31	24-04-2026	Friday	Poster	insta	Build a Global Business From Your Dining Table!	Published	
32	27-04-2026	Monday	Poster	insta	AI can write code, but it can’t replace Design Thinking.	Published	
33	28-04-2026	Tuesday	Reel	insta	Premium website develop Ajmal p sir	Published	
34			Poster	insta	Build the web.skip the code	Published	
35	29-04-2026	Wednesday	Reel	insta	Web Development thudanganam ennundu	Published	
36			Poster	insta	30 ദിവസത്തെ	Published	
37	30-04-2026	Thursday	Poster	Insta	Skip the wait.Start the career. 	Published	
38			Reel	Insta	Njingal epozhum scrool cheythukondirikugayaano	Published	
39			Poster	Insta	Labour day	Published	
40	05-01-2026	Friday	Poster	Insta	Switch to Tech easily	Published	
41			Reel	Insta	Joli thiraku Padana thiraku	Published	
42	05-02-2026	Saturday	Poster	Insta	Nooki ninnaal seat poogum	Published	
43			Reel	Insta	Ningalude buisness nu oru website veende	Published	
44	05-04-2026	Monday	Poster Carousel 4 page	Insta	Ready for your first step	Published	
45			Poster	Insta	Election കരിയറിന്റെ തലവരം മാറ്റാൻ — CODO അക്കാദമി 	Published	
46			Reel	Insta	Nni Recordingugal kandu confusion adikanda	Published	
47	05-05-2026	Tuesday	Poster	Insta	PLUS  2,Degree Kayinnillee 	Published	
48			Reel	Insta	100 peerude abeekshagal kidayil ninnum	Published	
49	05-06-2026	Wednesday	Poster MOCKUP	Insta	MOCKUP Ajency Albedo Albedo mein website mockup 	Published	
50			Reel	Insta	Website Domain hosting Webinar	Published	
51	05-07-2026	Thursday	Poster 	Insta	Joli urapoode padikaam	Published	
52	05-08-2026	Friday	Poster 	Insta	Thallala eth sathyam 	Published	
53	05-09-2026	Saturday	Poster  MOCKUP	Insta	Albedo Operation 1 st Mockup	Published	
54	05-11-2026	Monday	Poster	Insta	Nee annu paranna poole rishan child	Published	
55			Poster  MOCKUP	Insta	albedo operation 2 Mockup	Published	
56	05-12-2026	Tuesday	Poster	Insta	Ningalku pani aavum 	Published	
57	05-13-2026	Wednesday	Poster	Insta	Millenial and genz developer	Published	
58			Poster	Insta	Eni kariyar plan thettillaa	Published	
59	05-14-2026	Thursday	Poster	Insta	Hiring sales Executive	Published	
60			Poster	Insta	Developer Aakaan eni varshangal veenda	Published	
61	05-14-2026	Friday	Poster	Insta	SSLC WINNERS 	Published	
62	05-16-2026	Saturday	Poster	Insta	Eni sheriyaaya vayi	Published	
63	05-18-2026	Monday	Poster Mockup	Insta	Albedo operation 3 Mockup	Published	
64			Poster Mockup	Insta	Albedo operation 4 Mockup	Published	
65	05-19-2026	Tuesday	Reel	Insta	Reel cm codoyil padichaal joli kittumoo	Published	
66			Poster Mockup	Insta	Albedo Calc 1 Mockup	Published	
67			Poster Mockup	Insta	Albedo Calc 2 Mockup	Published	
68			Poster Mockup	Insta	Albedo Calc 3 Mockup	Published	
69			Poster Mockup	Insta	Albedo Calc 4 Mockup	Published	
70	05-19-2026	Wednesday	Poster Mockup	Insta	Albedo Support 1 Mockup	Published	
71			Poster Mockup	Insta	Albedo Support 2 Mockup	Published	
72			Poster Mockup	Insta	Albedo Support 3 Mockup	Published	
73			Poster Mockup	Insta	Albedo Support 4 Mockup	Published	
74			Poster	Insta	HR DAY	Published	
75	05-21-2026	Thursday	Poster	Insta	Chaai Day	Published	
76			Poster Mockup	Insta	Evoka Schoole 1 Mockup	Published	
77			Poster Mockup	Insta	Evoka Schoole 2 Mockup	Published	
78			Poster Mockup	Insta	Evoka Schoole 3 Mockup	Published	
79			Poster Mockup	Insta	Evoka Schoole 4 Mockup	Published	
80	05-22-2026	Friday	Poster	Insta	Cockroach poster	Published	
81			Poster Mockup	Insta	Evoka communication 1 Mockup	Published	
82			Poster Mockup	Insta	Evoka communication 2 Mockup	Published	
83			Poster Mockup	Insta	Evoka communication 3 Mockup	Published	
84			Poster Mockup	Insta	Evoka communication 4 Mockup	Published	
85	05-23-2026	Saturday	Poster	Insta	Shibin Birthday	Published	
86			Poster Mockup	Insta	KUG Frondent Website 1 Mockup	Published	
87			Poster Mockup	Insta	KUG Frondent Website 2 Mockup	Published	
88			Poster Mockup	Insta	KUG Frondent Website 3 Mockup	Published	
89			Poster Mockup	Insta	KUG Frondent Website 4 Mockup	Published	
90	05-25-2026	Monday	Poster Mockup	Insta	Tasqu Islamic Study 1 Mockup	Published	
91			Poster Mockup	Insta	Tasqu Islamic Study 2 Mockup	Published	
92			Poster Mockup	Insta	Tasqu Islamic Study 3 Mockup	Published	
93			Poster Mockup	Insta	Tasqu Islamic Study 4 Mockup	Published	
94	05-26-2026	Tuesday	Poster Mockup	Insta	KUG Resukt Publication Portal 1	Published	
95			Poster Mockup	Insta	KUG Resukt Publication Portal 2	Published	
96			Poster Mockup	Insta	KUG Resukt Publication Portal 3	Published	
97			Poster Mockup	Insta	KUG Resukt Publication Portal 4	Published	
98	05-27-2026	Wednesday	Poster	Insta	Eid poster  1	Published	
99			Poster	Insta	Eid poster  2	Published	
100	05-28-2026	Thursday		Insta	 EID HOLIDAY	None	
101	05-29-2026	Friday		Insta	EID HOLIDAY	None	
102	05-30-2026	Saturday	Reel	Insta	Reel Codo +2 Result	Published	
103	06-01-2026	Monday	Reel	Insta	Academy Internship it megala	Published	
104			Mockup	Insta	Albedo Educator Mockup 2	Published	
105	06-02-2026	Tuesday	Reel	Insta	Computer Knowledge kuravaano Ajmal Sir	Published	
106			Letter	Insta	Letter Head Shazia	Published	
107	06-03-2026	Wednesday	Mockup	Insta	Zeeque Plus LMS Mockup 1	Published	
108			Mockup	Insta	Zeeque Plus LMS Mockup 2	Published	
109			Mockup	Insta	Zeeque Plus LMS Mockup 3	Published	
110	06-04-2026	Thursday	Broucher	Insta	Broucher	Published	
111	06-05-2026	Friday	Poster	Insta	World Environment Day	Published	
112			Poster	Insta	2nd Anniversary	Published	
113			Reel	Insta	Ningal oru buisness owner aano	Published	
114	6/6/2026	Saturday	Reel	Insta	Free auditing	Published	
115	6/8/2026	Monday	Broucher		PIP BROUCHER	Published	
116	6/9/2026	Tuesday	Mockup	Insta	Zeeque Plus Website 1	Published	
117			Mockup	Insta	Zeeque Plus Website 2	Published	
118			Mockup	Insta	Zeeque Plus Website 3	Published	
119			Mockup	Insta	Zeeque Plus Website 4	Published	
120			Poster	Insta	Welcome poster Hashim	Published	
121			Poster	Insta	Welcome poster Nadha	Published	
122	6/10/2026	Wednesday	Mockup	Insta	Zeeque Magazine 1	Published	
123			Mockup	Insta	Zeeque Magazine 2	Published	
124			Mockup	Insta	Zeeque Magazine 3	Published	
125			Mockup	Insta	Zeeque Magazine 4	Published	
126			Poster	Insta	Web developer Internship	Published	
127			Poster	Insta	Cup aaru thuukum	Published	
128			Poster	Insta	Aarayalum web design	Published	
129	6/11/2026	Thursday	Poster	Insta	Instagram stoiry poster 1	Published	
130	6/12/2026	Friday	Shooting	Insta	4 video shooted	Published	
131	6/15/2026	Monday	Mockup	Insta	Zeequ PreeSchool Website 1	Published	
132			Mockup	Insta	Zeequ PreeSchool Website 2	Published	
133			Mockup	Insta	Zeequ PreeSchool Website 3	Published	
134			Mockup	Insta	Zeequ PreeSchool Website 4	Published	
135			Poster	Insta	3 Month Internship programme	Published	
136			Reel	Insta	3 month internship programme	Published	
137	6/16/2026	Tuesday	Poster	Insta	3 Month Internship programme 2	Published	
138			Reel	Insta	3 month internship programme 	Published	
139	6/17/2026	Wednesday	Shooting	Insta	6 video shoot 1 hashim sinan 2 3 video p sir		
140	6/18/2026	Thursday	Poster	Insta	3 Month Internship programme 3	Published	
141			Reel	Insta	Hashim Testimonial	Published	
142				Insta	Hashim Testimonial bts	Published	
143	6/19/2026	Friday	Reel	Insta	Ajmal p sir dubai	Published	
144	6/20/2026	Saturday	Poster	Insta	Happy fathers Day	Published	
145			Reel	Insta	Internship p sir	Published	
146			Poster	Insta	KSRTC	Published	
147	6/22/2026	Monday	Poster	Insta	Vidhuuramalla	Published	
148			Reel	Insta	Sinan testimonial	Published	
149	6/23/2026	Tuesday	Reel	Insta	P sir 3 month internship	Published	
150	6/24/2026	Wednesday	Shooting	Insta	3 video shot		
151	6/25/2026	Thursday	Reel	Insta	Hashim academy video	Published	
152			Poster Carousel	Insta	Carousel 3 Month internship	Published	
153	6/26/2026	Friday	Reel	Insta	Hashim Nadha troll	Published	
154			Poster	Insta	3 month internship	Published	
155	6/27/2026	Saturday	Poster	Insta	Fahis onboarding	Published	
156			Poster	Insta	Salman onboarding	Published	
157			Reel	Insta	Sinan Video	Published	
158	6/29/2026	Monday	Poster	Insta	3 months internship programme	Published	
159			Reel	Insta	P sir website reel	Published	
160	6/30/2026	Tuesday	Mockup	Insta	Klashra 1	Published	
161				Insta	Klashra 2	Published	
162				Insta	Klashra 3	Published	
163				Insta	Klashra 4	Published	
164			Reel	Insta	Sinan video	Published	
165	7/1/2026	Wednesday	Shooting	Insta	4 Video shot 3 hashim 1 hashim		
166				Insta	and nk sir		
167	7/2/2026	Thursday	Mockup	Insta	Bin Jabreen 1	Published	
168				Insta	Bin Jabreen 2	Published	
169				Insta	Bin Jabreen 3	Published	
170				Insta	Bin Jabreen 4	Published	
171			Reel	Insta	Sinan Ac	Published	
172			Reel	Insta	Mandhi Video	Published	
173	7/3/2026	Friday	Mockup	Insta	G7 holdings 1	Published	
174				Insta	G7 holdings 2	Published	
175				Insta	G7 holdings 3	Published	
176				Insta	G7 holdings 4	Published	
177			Reel	Insta	Hashim Vidoe	Published	
178	7/4/2026	Saturday	Poster	Insta	3 month internship programme	Published	
179	7/6/2026	Monday	Mockup	Insta	Nurse Xpro 1	Published	
180				Insta	Nurse Xpro 2	Published	
181				Insta	Nurse Xpro 3	Published	
182				Insta	Nurse Xpro 4	Published	
183			Video	Insta	Ajmal Nk and Hashim	Published	
184	7/7/2026	Tuesday	Mockup	Insta	Qmentr 1	Completed	
185				Insta	Qmentr 2	Completed	
186				Insta	Qmentr 3	Completed	
187				Insta	Qmentr 4	Completed	
188			Video	Insta	Hashim	Published	
189	7/8/2026	Wednesday	Shooting	Insta	video hashim 1 shooting	Completed	
190	7/9/2026	Thursday	Mockup	Insta	Skill mount 1	Completed	
191				Insta	Skill mount 2	Completed	
192				Insta	Skill mount 3	Completed	
193				Insta	Skill mount 4	Completed	
194			Video	Insta	video Hashim	Published	
195	7/10/2026	Friday	Mockup	Insta	Futurex 1	Completed	
196				Insta	Futurex 2	Completed	
197				Insta	Futurex 3	Completed	
198				Insta	Futurex 4	Completed	
199			Poster	Insta	Rumana Birthday	Published	
200			Video	Insta	Hashim client call	Published	
201			Logo web	Insta	4 size logo	Published	
202			Document	Insta	website documnent	Completed	
203	7/11/2026	Saturday	Mockup	Insta	Darna 1	Completed	
204				Insta	Darna 2	Completed	
205				Insta	Darna 3	Completed	
206				Insta	Darna 4	Completed	
207	7/13/2026	Monday	Mockup	Insta	MEdosuit 1	Completed	
208				Insta	MEdosuit 2	Completed	
209				Insta	MEdosuit 3	Completed	
210				Insta	MEdosuit 4	Completed	
211	7/14/2026	Tuesday	Mockup	Insta	PVK 1	Completed	
212				Insta	PVK 2	Completed	
213				Insta	PVK 3	Completed	
214				Insta	PVK 4	Completed	
215	7/15/2026	Wednesday	Mockup	Insta	CBMS MARiage fund 1	Completed	
216				Insta	CBMS MARiage fund 2	Completed	
217				Insta	CBMS MARiage fund 3	Completed	
218				Insta	CBMS MARiage fund 4	Completed	
219			Poster	Insta	Jubairiya growth glimpse	Published	
220			Tip	Insta	Tip 5 Page Carousel	Published	
221	7/16/2026	Thursday	Poster	Insta	Growth glimpse png poster	Published	
222			Poster	Insta	Debate png poster	Published	
223	7/17/2026	Friday	Shooting	Insta	4 video 2 hashim 2 nk	Published	
224	7/18/2026	Saturday	Mockup	Insta	VSAF 1	Completed	
225				Insta	VSAF 2	Completed	
226				Insta	VSAF 3	Completed	
227				Insta	VSAF 4	Completed	
228			Poster	Insta	Debate Poster	Published	
229			Video	Insta	Hashim video	Published	
230			Document	Insta	Tripfly	Published	
231			Logo	Insta	PVK 	Published	
232			Calender	Insta	Poster calendar	Published	
233			Upload	Insta	Drive Upload all mockups	Published	
234	7/20/2026	Monday	MOCkup	Insta	Macadz 1	Completed	
235				Insta	Macadz 2	Completed	
236				Insta	Macadz 3	Completed	
237				Insta	Macadz 4	Completed	
238			Video	Insta	Nk sir video	Published	
239			Tip	Insta	 Tip 5 page Carousel	Published	
240			Catalogue	Insta	Catalogue hand book	Published	
241	7/21/2026	Tuesday	Mockup	Insta	Nexaar 1	Completed	
242				Insta	Nexaar 2	Completed	
243				Insta	Nexaar 3	Completed	
244				Insta	Nexaar 4	Completed	
245			Video	Insta	Hashim reel class cut	Published	
246	7/22/2026	Wednesday	Mockup	Insta	2.0 1	Completed	
247				Insta	2.0 2	Completed	
248				Insta	2.0 3	Completed	
249				Insta	2.0 4	Completed	
250			Poster	Insta	Shibin growth poster	Published	
251			Video	Insta	Nk sir child reel	Published	
252	7/23/2026	Thursday	Mockup	Insta	Japaneese 1	Completed	
253				Insta	Japaneese 2	Completed	
254				Insta	Japaneese 3	Completed	
255				Insta	Japaneese 4	Completed	
256			Tip	Insta	Tip 5 page	Published	
257	7/25/2026	Saturday	Mockup	Insta	Sindal1	Completed	
258				Insta	Sindal2	Completed	
259				Insta	Sindal3	Completed	
260				Insta	Sindal4	Completed	
261			Poster	Insta	Kargil vijay diwas	Published	
262			Document	Insta	Shareefa certificate	Published	
263	7/27/2026	Monday	Mockup	Insta	Little Talks 1	Completed	
264				Insta	Little Talks 2	Completed	
265				Insta	Little Talks 3	Completed	
266				Insta	Little Talks 4	Completed	
267			Poster	Insta	Growth Glimpse rumana	Published	
268			Tip	Insta	Tip 5 page	Published	
269	7/28/2026	Tuesday	Mockup	Insta	dsign 1	Completed	
270				Insta	dsign 2	Completed	
271				Insta	dsign 3	Completed	
272				Insta	dsign 4	Completed	
273			Poster	Insta	Career pressure	Published	
274	7/29/2026	Wednesday	Mockup	Insta	Europe calling 1	Completed	
275				Insta	Europe calling 2	Completed	
276				Insta	Europe calling 3	Completed	
277				Insta	Europe calling 4	Completed	
278			Poster	Insta	Hashim growth glimpse	Published	
279	7/30/2026	Thursday	Mockup	Insta	Codo ai 1	Completed	
280				Insta	Codo ai 2	Completed	
281				Insta	Codo ai 3	Completed	
282				Insta	Codo ai 4	Completed	
			Tip	Insta	Tip 5 page		
283	8/1/2026	Saturday	Mockup	Insta	Codo academy 1	Completed	
284				Insta	Codo academy 2	Completed	
285				Insta	Codo academy 3	Completed	
286				Insta	Codo academy 4	Completed	
287			Poster	Insta	Child poster	Published	
288			Tip	Insta	Tip 5 page	Published	
289	8/3/2026	Monday	MOCKUP	Insta	Just go taxi 1	Completed	
290				Insta	Just go taxi 2	Completed	
291				Insta	Just go taxi 3	Completed	
292				Insta	Just go taxi 4	Completed	
293			Tip	Insta	5 page carousel	Published	
294	8/4/2026	Tuesday	Mockup	Insta	Multy safety 1	Completed	
295				Insta	Multy safety 2	Completed	
296				Insta	Multy safety 3	Completed	
297				Insta	Multy safety 4	Completed	
298	8/5/2026	Wednesday	Mockup	Insta	Silver hills global 1	Completed	
299				Insta	Silver hills global 2	Completed	
300				Insta	Silver hills global 3	Completed	
301				Insta	Silver hills global 4	Completed	
302			TIp	Insta	5 page carousel	Published	
303	8/6/2026	Thursday	Mockup	Insta	Next gen 1	Completed	
304				Insta	Next gen 2	Completed	
305				Insta	Next gen 3	Completed	
306				Insta	Next gen 4	Completed	
307	8/7/2026	Friday	Document	Insta	Kotta Deals Logo Concept document	Published	
308	8/8/2026	Saturday	Tip	Insta	5 page carousel	Published	
309	8/10/2026	Monday	Tip	Insta	5 page carousel	Published	
310			Document	Insta	Design kit kotta deals	Published	
311	8/11/2026	Tuesday	Poster	Insta	youth day poster	Published	
312	8/13/2026	Thursday	Poster	Insta	17 poster profile	Published	
313			Tip	Insta	5 page carousel	Completed	
314	8/14/2026	Friday	Logo	Insta	Logo next gen	Published	
315	8/15/2026	Saturday	Poster	Insta	Independence day1	Published	
316			Poster	Insta	Independence day2	Published	
317	8/17/2026	Monday	Mockup	Insta	Albedo mockup app 1	Completed	
318				Insta	Albedo mockup app 2	Completed	
319				Insta	Albedo mockup app 3	Completed	
320				Insta	Albedo mockup app 4	Completed	
321				Insta	Evoka mockup app 1	Completed	
322				Insta	Evoka mockup app 2	Completed	
323				Insta	Evoka mockup app 3	Completed	
324				Insta	Evoka mockup app 4	Completed	
325			Tip	Insta	5 page carousel	Completed	
'''

WEEKDAYS = {
    "sunday",
    "monday",
    "tuesday",
    "wednesday",
    "thursday",
    "friday",
    "saturday",
}

AGENCY_RE = re.compile(
    r"albedo|evoka|zeeque|zeequ|kug |tasqu|klashra|jabreen|g7 |nurse xpro|"
    r"qmentr|skill mount|futurex|darna|medosuit|pvk|cbms|vsaf|macadz|nexaar|"
    r"japaneese|sindal|little talks|dsign|europe calling|just go taxi|"
    r"multy safety|silver hills|next gen|kotta|tripfly|pip broucher|"
    r"mockup app|agency",
    re.I,
)

NAMESPACE = uuid.UUID("c0de2026-a11e-4ead-9a00-000000000000")


def sql_str(value: str | None) -> str:
    if value is None or value == "":
        return "NULL"
    return "'" + value.replace("\\", "\\\\").replace("'", "''") + "'"


def parse_date(raw: str, weekday_hint: str = "", last_date: str | None = None) -> str | None:
    raw = raw.strip()
    if not raw:
        return None
    candidates: list[datetime] = []
    seen: set[str] = set()
    for fmt in ("%d-%m-%Y", "%m-%d-%Y", "%m/%d/%Y", "%d/%m/%Y"):
        try:
            dt = datetime.strptime(raw, fmt)
        except ValueError:
            continue
        key = dt.strftime("%Y-%m-%d")
        if key not in seen:
            seen.add(key)
            candidates.append(dt)
    if not candidates:
        return None

    hint = weekday_hint.strip().lower()
    if hint:
        matched = [d for d in candidates if d.strftime("%A").lower() == hint]
        if matched:
            candidates = matched

    if last_date:
        last = datetime.strptime(last_date, "%Y-%m-%d")
        after = [d for d in candidates if d.date() >= last.date()]
        if after:
            candidates = after

    candidates.sort()
    return candidates[0].strftime("%Y-%m-%d")


def map_material(material: str, title: str) -> str:
    blob = f"{material} {title}".lower()
    if "mockup app" in blob:
        return "Mockup App"
    if "mockup" in blob or "mock up" in blob:
        return "Mockup Web"
    if "carousel" in blob or re.search(r"\b5 page\b", blob):
        return "Carousel"
    if re.search(r"\btip\b", blob):
        return "Tips"
    if "brochure" in blob or "broucher" in blob:
        return "Brochure"
    if "logo" in blob:
        return "Logo"
    if "document" in blob or "letter" in blob or "certificate" in blob:
        return "Document"
    if re.search(r"\breel\b", blob):
        return "Reel"
    if "poster" in blob:
        return "Poster"
    return "Other"


def map_platform(platform: str) -> str:
    p = platform.strip().lower()
    if p in {"", "insta", "instagram"}:
        return "Insta"
    if "youtube" in p:
        return "YouTube"
    if "linkedin" in p:
        return "LinkedIn"
    if p == "web":
        return "Web"
    return "Insta"


def map_status(status: str) -> str:
    s = status.strip().lower()
    if s == "published":
        return "Published"
    if s == "completed":
        return "Completed"
    if s in {"in review", "review"}:
        return "In Review"
    if s in {"rejected"}:
        return "Rejected"
    return "Draft"


def parse_rows() -> list[dict]:
    rows: list[dict] = []
    last_date: str | None = None
    last_material = ""
    seq = 0
    for line in RAW.splitlines():
        if not line.strip():
            continue
        parts = line.split("\t")
        while len(parts) < 8:
            parts.append("")
        num, date_raw, weekday, material, platform, content, status, _extra = parts[:8]
        date_raw = date_raw.strip()
        weekday = weekday.strip()
        material = material.strip()
        platform = platform.strip()
        content = re.sub(r"\s+", " ", content).strip()
        status = status.strip()

        if weekday.lower() in WEEKDAYS and not date_raw:
            pass
        iso = parse_date(date_raw, weekday, last_date)
        if iso:
            last_date = iso
        if material:
            last_material = material
        elif not material and last_material and "holiday" not in content.lower():
            material = last_material

        if not content:
            continue

        seq += 1
        material_type = map_material(material, content)
        st = map_status(status)
        scheduled = last_date
        published = scheduled if st == "Published" else None
        title = content[:255]
        project_kind = "agency" if AGENCY_RE.search(f"{material} {content}") else "academy"
        asset_id = str(uuid.uuid5(NAMESPACE, f"nadha-creative-{seq:04d}"))
        rows.append(
            {
                "id": asset_id,
                "seq": seq,
                "title": title,
                "material_type": material_type,
                "platform": map_platform(platform),
                "hook": content,
                "status": st,
                "scheduled": scheduled,
                "published": published,
                "project_kind": project_kind,
                "source_no": num.strip() or str(seq),
            }
        )
    return rows


def main() -> None:
    rows = parse_rows()
    out = Path(__file__).with_name("091_seed_nadha_creative_assets.sql")
    lines = [
        "-- Seed BugCreative assets from Nadha's tracker (safe to re-run).",
        "-- Maps sheet columns: date, material, platform, hook, status.",
        "-- Creator: nadha_rahman (fallback: first creator user).",
        "-- Project: CODO Academy creatives vs CODO Agency creatives when those names exist.",
        "--",
        "-- mysql -u USER -p DATABASE < backend/migrations/091_seed_nadha_creative_assets.sql",
        "",
        "SET NAMES utf8mb4;",
        "",
        "SET @creator_id := (",
        "  SELECT id FROM users",
        "  WHERE LOWER(username) IN ('nadha_rahman', 'nadha')",
        "  ORDER BY FIELD(LOWER(username), 'nadha_rahman', 'nadha')",
        "  LIMIT 1",
        ");",
        "SET @creator_id := COALESCE(",
        "  @creator_id,",
        "  (SELECT id FROM users WHERE role = 'creator' ORDER BY created_at ASC LIMIT 1)",
        ");",
        "",
        "SET @academy_id := (",
        "  SELECT id FROM projects",
        "  WHERE name LIKE '%CODO Academy creat%'",
        "  ORDER BY created_at ASC LIMIT 1",
        ");",
        "SET @agency_id := (",
        "  SELECT id FROM projects",
        "  WHERE name LIKE '%CODO Agency creat%'",
        "  ORDER BY created_at ASC LIMIT 1",
        ");",
        "",
        "DELETE cr FROM creative_reviews cr",
        "INNER JOIN creative_assets a ON a.id = cr.asset_id",
        "WHERE a.hook_content LIKE '%[sheet-seed:nadha-2026]%';",
        "",
        "DELETE FROM creative_assets",
        "WHERE hook_content LIKE '%[sheet-seed:nadha-2026]%';",
        "",
        "INSERT INTO creative_assets (",
        "  id, project_id, creator_id, title, material_type, platform,",
        "  hook_content, asset_source, drive_link, uploaded_file_path,",
        "  preview_thumbnail_url, status, scheduled_date, published_date",
        ") VALUES",
    ]

    value_rows = []
    for r in rows:
        project_sql = "@agency_id" if r["project_kind"] == "agency" else "@academy_id"
        hook = f"{r['hook']}\n[sheet-seed:nadha-2026 #{r['source_no']}]"
        value_rows.append(
            "(\n"
            f"  {sql_str(r['id'])},\n"
            f"  {project_sql},\n"
            "  @creator_id,\n"
            f"  {sql_str(r['title'])},\n"
            f"  {sql_str(r['material_type'])},\n"
            f"  {sql_str(r['platform'])},\n"
            f"  {sql_str(hook)},\n"
            "  'link',\n"
            "  NULL,\n"
            "  NULL,\n"
            "  NULL,\n"
            f"  {sql_str(r['status'])},\n"
            f"  {sql_str(r['scheduled'])},\n"
            f"  {sql_str(r['published'])}\n"
            ")"
        )

    lines.append(",\n".join(value_rows) + ";")
    lines.extend(
        [
            "",
            f"-- Imported {len(rows)} assets.",
            "SELECT COUNT(*) AS seeded_count",
            "FROM creative_assets",
            "WHERE hook_content LIKE '%[sheet-seed:nadha-2026]%';",
            "",
        ]
    )
    out.write_text("\n".join(lines), encoding="utf-8")
    print(f"Wrote {len(rows)} rows to {out}")


if __name__ == "__main__":
    main()
