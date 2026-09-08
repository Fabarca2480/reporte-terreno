const express=require("express");
const multer=require("multer");
const fs=require("fs");
const path=require("path");
const crypto=require("crypto");

const app=express();

const PORT=process.env.PORT||3000;

const DATA_DIR=process.env.DATA_DIR||path.join(__dirname,"data");
const DB=path.join(DATA_DIR,"reportes.json");
const UP=process.env.UPLOADS_DIR||path.join(DATA_DIR,"uploads");

fs.mkdirSync(DATA_DIR,{recursive:true});
fs.mkdirSync(UP,{recursive:true});

if(!fs.existsSync(DB)) fs.writeFileSync(DB,"[]");

app.use(express.json({limit:"2mb"}));

app.use(express.static(path.join(__dirname,"public")));

app.use("/uploads",express.static(UP));

const storage=multer.diskStorage({
  destination:(r,f,cb)=>cb(null,UP),
  filename:(r,f,cb)=>cb(
    null,
    Date.now()+"-"+crypto.randomBytes(5).toString("hex")+path.extname(f.originalname||".jpg")
  )
});

const upload=multer({
  storage,
  limits:{
    files:8,
    fileSize:10*1024*1024
  }
});

const read=()=>JSON.parse(fs.readFileSync(DB,"utf8"));

const write=x=>{
  const t=DB+".tmp";
  fs.writeFileSync(t,JSON.stringify(x,null,2));
  fs.renameSync(t,DB);
};

app.get("/api/health",(q,s)=>s.json({ok:true}));

app.get("/api/reports",(q,s)=>{
  let a=read(),p=q.query;

  if(p.q){
    let x=p.q.toLowerCase();
    a=a.filter(r=>
      (r.id+" "+r.lugar+" "+r.trabajo)
      .toLowerCase()
      .includes(x)
    );
  }

  if(p.desde)
    a=a.filter(r=>(r.fechaInicio||"")>=p.desde);

  if(p.hasta)
    a=a.filter(r=>(r.fechaInicio||"")<=p.hasta);

  if(p.estado)
    a=a.filter(r=>r.estado===p.estado);

  a.sort((x,y)=>
    (y.createdAt||"").localeCompare(x.createdAt||"")
  );

  s.json(a);
});

app.post("/api/reports",upload.array("fotos",8),(req,res)=>{
  try{
    const r=JSON.parse(req.body.report||"{}");

    if(
      !r.lugar||
      !r.trabajo||
      !r.horaInicio||
      !r.horaTermino
    ){
      return res.status(400).json({
        error:"Faltan datos obligatorios"
      });
    }

    r.createdAt=new Date().toISOString();

    r.estadoEnvio="RECIBIDO";

    r.fotos=(req.files||[]).map(f=>({
      nombre:f.originalname,
      url:"/uploads/"+f.filename
    }));

    let a=read();

    a.push(r);

    write(a);

    res.status(201).json({
      ok:true,
      report:r
    });

  }catch(e){

    console.error(e);

    res.status(400).json({
      error:"No fue posible procesar el reporte"
    });
  }
});

app.get(/.*/,(q,s)=>
  s.sendFile(
    path.join(__dirname,"public","index.html")
  )
);

app.listen(PORT,"0.0.0.0",()=>
  console.log(
    "Reporte Terreno V3 en puerto "+PORT
  )
);
